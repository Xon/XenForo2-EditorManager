<?php

/*!
 * KL/EditorManager/Admin/Controller/Fonts.php
 * License https://creativecommons.org/licenses/by-nc-nd/4.0/legalcode
 * Copyright 2017 Lukas Wieditz
 */

namespace KL\EditorManager\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;
use XF\Util\File;

/**
 * COLUMNS
 * @property int|null audio_id
 * @property string url
 * @property string url_hash
 * @property int file_size
 * @property string file_name
 * @property string mime_type
 * @property int fetch_date
 * @property int first_request_date
 * @property int last_request_date
 * @property int views
 * @property bool pruned
 * @property int is_processing
 * @property int failed_date
 * @property int fail_count
 *
 * RELATIONS
 * @property \KL\EditorManager\Entity\AudioProxyReferrer[] Referrers
 */
class AudioProxy extends Entity
{
    protected $placeholderPath;

    /**
     * @param $filePath
     * @param $mimeType
     * @param null $fileName
     */
    public function setAsPlaceholder($filePath, $mimeType, $fileName = null)
    {
        if ($this->placeholderPath) {
            throw new \InvalidArgumentException("Once a audio is marked as a placeholder, it cannot be changed");
        }

        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("Placeholder path '$filePath' doesn't exist or isn't readable");
        }

        $this->placeholderPath = $filePath;
        $this->file_name = $fileName ?: basename($filePath);
        $this->mime_type = $mimeType;
        $this->file_size = filesize($filePath);

        $this->setReadOnly(true);
    }

    /**
     * @return bool
     */
    public function isPlaceholder()
    {
        return $this->placeholderPath ? true : false;
    }

    /**
     * @return mixed
     */
    public function getPlaceholderPath()
    {
        return $this->placeholderPath;
    }

    /**
     * @return string
     */
    public function getAbstractedAudioPath()
    {
        return sprintf('internal-data://audio_cache/%d/%d-%s.data',
            floor($this->audio_id / 1000),
            $this->audio_id,
            $this->url_hash
        );
    }

    /**
     * @return bool
     */
    public function isValid()
    {
        if ($this->pruned) {
            return false;
        }

        return $this->app()->fs()->has($this->getAbstractedAudioPath());
    }

    /**
     * @return bool
     */
    public function isRefreshRequired()
    {
        if ($this->placeholderPath) {
            return false;
        }

        $filePath = $this->getAbstractedAudioPath();
        $fs = $this->app()->fs();

        if ($this->is_processing && \XF::$time - $this->is_processing < 5) {
            if ($fs->has($filePath)) {
                return false;
            }

            $maxSleep = 5 - (\XF::$time - $this->is_processing);
            for ($i = 0; $i < $maxSleep; $i++) {
                if ($fs->has($filePath)) {
                    return false;
                }
            }
        }

        if ($this->failed_date && $this->fail_count) {
            return $this->isFailureRefreshRequired();
        }

        if ($this->pruned) {
            return true;
        }

        $ttl = $this->app()->options()->klEMAudioCacheTTL;
        if ($ttl && $this->fetch_date < \XF::$time - $ttl * 86400) {
            return true;
        }

        if (!$fs->has($filePath)) {
            return true;
        }

        $refresh = $this->app()->options()->klEMAudioCacheRefresh;
        if ($refresh && !$this->fail_count && $this->fetch_date < \XF::$time - $refresh * 86400) {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isFailureRefreshRequired()
    {
        if (!$this->failed_date || !$this->fail_count) {
            return false;
        }

        switch ($this->fail_count) {
            case 1:
                $delay = 60;
                break; // 1 minute
            case 2:
                $delay = 5 * 60;
                break; // 5 minutes
            case 3:
                $delay = 30 * 60;
                break; // 30 minutes
            case 4:
                $delay = 3600;
                break; // 1 hour
            case 5:
                $delay = 6 * 3600;
                break; // 6 hours

            default:
                $delay = ($this->fail_count - 5) * 86400; // 1, 2, 3... days
        }

        return \XF::$time >= ($this->failed_date + $delay);
    }

    /**
     * @return null|string
     */
    public function getETagValue()
    {
        if ($this->isPlaceholder() || $this->fail_count || $this->pruned) {
            return null;
        }

        return sha1($this->url . $this->fetch_date);
    }

    /**
     * @return bool
     * @throws \XF\PrintableException
     */
    public function prune()
    {
        if ($this->placeholderPath) {
            return false;
        }

        $this->pruned = true;
        $this->save();

        File::deleteFromAbstractedPath($this->getAbstractedAudioPath());

        return true;
    }

    /**
     * @param $fileName
     * @return bool
     */
    protected function verifyFileName(&$fileName)
    {
        if (!preg_match('/./u', $fileName)) {
            $fileName = preg_replace('/[\x80-\xFF]/', '?', $fileName);
        }

        $fileName = \XF::cleanString($fileName);

        // ensure the filename fits -- if it's too long, take off from the beginning to keep extension
        $length = utf8_strlen($fileName);
        if ($length > 250) {
            $fileName = utf8_substr($fileName, $length - 250);
        }

        return true;
    }

    /**
     * @param $url
     * @return bool
     */
    protected function verifyUrl(&$url)
    {
        $url = $this->getProxyRepo()->cleanUrlForFetch($url);

        if (!preg_match('#^https?://#i', $url)) {
            $this->error('Developer: invalid URL', 'url');
            return false;
        }

        return true;
    }

    protected function _preSave()
    {
        if ($this->placeholderPath) {
            throw new \LogicException("Cannot save placeholder audio");
        }

        if ($this->isChanged('url')) {
            $this->url_hash = md5($this->url);
        }
    }

    /**
     * @param Structure $structure
     * @return Structure
     */
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_kl_em_audio_proxy';
        $structure->shortName = 'KL\EditorManager:AudioProxy';
        $structure->primaryKey = 'audio_id';
        $structure->columns = [
            'audio_id' => ['type' => self::UINT, 'nullable' => true, 'autoIncrement' => true],
            'url' => ['type' => self::STR, 'required' => true],
            'url_hash' => ['type' => self::STR, 'maxLength' => 32, 'required' => true],
            'file_size' => ['type' => self::UINT, 'default' => 0],
            'file_name' => ['type' => self::STR, 'maxLength' => 250, 'default' => ''],
            'mime_type' => ['type' => self::STR, 'maxLength' => 100, 'default' => ''],
            'fetch_date' => ['type' => self::UINT, 'default' => 0],
            'first_request_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'last_request_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'views' => ['type' => self::UINT, 'default' => 0],
            'pruned' => ['type' => self::BOOL, 'default' => false],
            'is_processing' => ['type' => self::UINT, 'default' => 0],
            'failed_date' => ['type' => self::UINT, 'default' => 0],
            'fail_count' => ['type' => self::UINT, 'default' => 0],
        ];
        $structure->getters = [];
        $structure->relations = [
            'Referrers' => [
                'entity' => 'KL\EditorManager:AudioProxyReferrer',
                'type' => self::TO_MANY,
                'conditions' => 'audio_id',
                'order' => ['last_date', 'DESC']
            ]
        ];

        return $structure;
    }

    /**
     * @return \KL\EditorManager\Repository\AudioProxy
     */
    protected function getProxyRepo()
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->repository('KL\EditorManager:AudioProxy');
    }
}