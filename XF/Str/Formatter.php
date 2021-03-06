<?php

/*!
 * KL/EditorManager/Str/Formatter.php
 * License https://creativecommons.org/licenses/by-nc-nd/4.0/legalcode
 * Copyright 2017 Lukas Wieditz
 */

namespace KL\EditorManager\XF\Str;

use KL\EditorManager\Entity\CustomEmote;
use KL\EditorManager\XF\Template\Templater;
use XF\Entity\User;
use XF\Pub\App;

/**
 * Class Formatter
 * @package KL\EditorManager\XF\Str
 */
class Formatter extends XFCP_Formatter
{
    /**
     * @var User
     */
    protected $klEmContextUser = null;

    /**
     * @var array
     */
    protected $klEmUserSmilieCache = [
        'translate' => [],
        'reverse' => []
    ];

    /**
     * @var array
     */
    protected $klEmSmilieCache = [];

    protected $klEmCustomEmotes = [];

    /**
     * @return User
     */
    public function getKlEmContextUser()
    {
        if(\XF::app() instanceof App) {
            return $this->klEmContextUser ?: \XF::visitor();
        }

        return null;
    }

    /**
     * @param User $klEmContextUser
     */
    public function setKlEmContextUser(User $klEmContextUser = null)
    {
        $this->klEmContextUser = $klEmContextUser;
    }

    /**
     * @param array $smilies
     */
    public function addSmilies(array $smilies)
    {
        array_push($this->klEmSmilieCache, ...$smilies);
        parent::addSmilies($smilies);
    }

    /**
     * @param string $context
     * @return mixed
     */
    protected function getKlEmUserSmilieTranslate($context = 'translate')
    {
        $contextUser = $this->getKlEmContextUser();

        if (!isset($this->klEmUserSmilieCache['reverse'][$contextUser->user_id])) {
            $this->klEmUserSmilieCache['reverse'][$contextUser->user_id] = [];
            $this->klEmUserSmilieCache['translate'][$contextUser->user_id] = [];

            $smilies = $this->klEmSmilieCache;


            // TODO: MOVE TO REPO
            $customEmotes = \XF::finder('KL\EditorManager:CustomEmote')
                ->where('user_id', '=',
                    $contextUser->user_id)->fetch();

            $this->klEmCustomEmotes += $customEmotes->toArray();

            foreach ($customEmotes as $customEmote) {
                /** @var CustomEmote $customEmote */
                $smilies[] = [
                    'smilie_id' => 'klce' . $customEmote->emote_id,
                    'title' => $customEmote->title,
                    'image_url' => $customEmote->getEmoteUrl(),
                    'image_url_2x' => 22,
                    'sprite_params' => [
                        'w' => 22,
                        'h' => 22,
                        'x' => 0,
                        'y' => 0,
                        'bs' => 'contain'
                    ],
                    'kl_em_active' => true,
                    'kl_em_user_criteria' => null,
                    'smilieText' => [':' . $customEmote->Prefix->prefix . $customEmote->replacement . ':']
                ];
            }
            // TODO: MOVE TO REPO END

            foreach ($smilies AS $smilie) {
                if (isset($smilie['kl_em_active']) && !$smilie['kl_em_active']) {
                    continue;
                }

                $criteria = \XF::app()->criteria('XF:User', $smilie['kl_em_user_criteria'] ?: []);
                $criteria->setMatchOnEmpty(true);

                if ($criteria->isMatched($contextUser)) {
                    foreach ($smilie['smilieText'] AS $text) {
                        $this->klEmUserSmilieCache['translate'][$contextUser->user_id][$text] = "\0" . $smilie['smilie_id'] . "\0";
                    }

                    $this->klEmUserSmilieCache['reverse'][$contextUser->user_id][$smilie['smilie_id']] = $smilie;
                }
            }
        }

        return $this->klEmUserSmilieCache[$context][$contextUser->user_id];
    }

    /**
     * @param $text
     * @return null|string|string[]
     */
    public function replaceSmiliesHtml($text)
    {
        if (!$this->getKlEmContextUser()) {
            return parent::replaceSmiliesHtml($text);
        }

        $cache = &$this->smilieCache;

        $replace = function ($id, $smilie) use (&$cache) {
            if (strpos($id, 'klce') === 0) {
                /** @var Templater $templater */
                $templater = \XF::app()->templater();

                // TODO: MOVE TO REPO
                return $templater->fnKlEmCustomEmote($templater, $escape, $this->klEmCustomEmotes[substr($id, 4)]);
            }

            if (isset($cache[$id])) {
                return $cache[$id];
            }

            $html = $this->getDefaultSmilieHtml($id, $smilie);
            $cache[$id] = $html;
            return $html;
        };

        return $this->replaceSmiliesInText($text, $replace, 'htmlspecialchars');
    }

    /**
     * @param $text
     * @param $replaceCallback
     * @param null $escapeCallback
     * @return null|string|string[]
     */
    public function replaceSmiliesInText($text, $replaceCallback, $escapeCallback = null)
    {
        $contextUser = $this->getKlEmContextUser();

        if (!$contextUser) {
            return parent::replaceSmiliesInText($text, $replaceCallback, $escapeCallback);
        }

        $smilieTranslate = $this->getKlEmUserSmilieTranslate();

        if ($smilieTranslate) {
            $text = strtr($text, $smilieTranslate);
        }

        if ($escapeCallback) {
            /** @var callable $escapeCallback */
            $text = $escapeCallback($text);
        }

        if ($smilieTranslate) {
            $reverse = $this->getKlEmUserSmilieTranslate('reverse');

            $text = preg_replace_callback('#\0((?:klce)?\d+)\0#', function ($match) use ($reverse, $replaceCallback) {
                $id = $match[1];
                return isset($reverse[$id]) ? $replaceCallback($id, $reverse[$id]) : '';
            }, $text);
        }

        return $text;
    }

    /**
     * Ugly function override, might be a source of incompatibility.
     * @param $bbCode
     * @param $context
     * @return string
     */
    public function getBbCodeForQuote($bbCode, $context)
    {
        $bbCodeContainer = \XF::app()->bbCode();

        $processor = $bbCodeContainer->processor()
            ->addProcessorAction('quotes', $bbCodeContainer->processorAction('quotes'))
            ->addProcessorAction('censor', $bbCodeContainer->processorAction('censor'))
            ->addProcessorAction('stripHide', $bbCodeContainer->processorAction('\KL\EditorManager:StripHide'));

        return trim($processor->render($bbCode, $bbCodeContainer->parser(), $bbCodeContainer->rules($context)));
    }


    /**
     * Strips hide BB codes from snipptes to prevent them from being rendered plain.
     * @param $string
     * @param int $maxLength
     * @param array $options
     * @return mixed|string
     */
    public function snippetString($string, $maxLength = 0, array $options = [])
    {
        $string = preg_replace("#\[(HIDE(?:REPLY|POSTS|THANKS|REPLYTHANKS){0,1})].*?\[\/\g1]#si",
            \XF::phrase('kl_em_hidden_content'), $string);
        $string = parent::snippetString($string, $maxLength, $options);
        return $string;
    }
}