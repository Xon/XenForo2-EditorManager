<?php

/*!
 * KL/EditorManager/Pub/Controller/Thread.php
 * License https://creativecommons.org/licenses/by-nc-nd/4.0/legalcode
 * Copyright 2020 Lukas Wieditz
 */

namespace KL\EditorManager\XF\Pub\Controller;

use XF;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\Exception;
use XF\Mvc\Reply\Redirect;
use XF\Mvc\Reply\View;

/**
 * Class Thread
 * @package KL\EditorManager\XF\Pub\Controller
 */
class Thread extends XFCP_Thread
{
    /**
     * @param ParameterBag $params
     * @return Redirect|View
     * @throws Exception
     */
    public function actionAddReply(ParameterBag $params): AbstractReply
    {
        $return = parent::actionAddReply($params);

        if ($return instanceof View) {
            $messagesPerPage = XF::options()->messagesPerPage;
            $offset = max(0, ($this->filter('klPage', 'uint') ?: 0) - 1) * $messagesPerPage;

            $thread = $this->assertViewableThread($params['thread_id']);

            $finder = XF::finder('XF:Post');

            $posts = $finder
                ->where('thread_id', '=', $thread->thread_id)
                ->whereSQL("LOWER(message) LIKE '%[hide%'")
                ->fetch($messagesPerPage, $offset);

            $return->setJsonParam('klEMPosts', $posts);
        }

        return $return;
    }
}