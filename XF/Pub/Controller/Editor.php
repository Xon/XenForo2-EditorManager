<?php

/*!
 * KL/EditorManager/Admin/Controller/Fonts.php
 * License https://creativecommons.org/licenses/by-nc-nd/4.0/legalcode
 * Copyright 2017 Lukas Wieditz
 */

namespace KL\EditorManager\XF\Pub\Controller;

use KL\EditorManager\Repository\SpecialChars;
use KL\EditorManager\XF\Repository\Smilie;
use XF\Mvc\Reply\View;

/**
 * Class Editor
 * @package KL\EditorManager\Pub\Controller
 */
class Editor extends XFCP_Editor
{
    /**
     * @return \XF\Mvc\Reply\View
     */
    public function actionFindGFont()
    {
        $q = ltrim($this->filter('q', 'str', ['no-trim']));

        if ($q !== '' && utf8_strlen($q) >= 2) {
            /** @var \KL\EditorManager\Finder\GoogleFont $finder */
            $finder = $this->finder('KL\EditorManager:GoogleFont');

            $fonts = $finder
                ->where('font_id', 'like', $finder->escapeLike($q, '%?%'))
                ->active()
                ->fetch(10);
        } else {
            $fonts = [];
            $q = '';
        }

        $viewParams = [
            'q' => $q,
            'fonts' => $fonts
        ];
        return $this->view('KL\EditorManager:GFont\Find', '', $viewParams);
    }

    /**
     * @param $dialog
     * @return array
     */
    protected function loadDialog($dialog)
    {
        $view = 'XF:Editor\Dialog';
        $template = null;
        $params = [];

        switch ($dialog) {
            case 'ispoiler':
                $template = "editor_dialog_kl_em_ispoiler";
                break;

            case 'gfont':
                /** @var \KL\EditorManager\Finder\GoogleFont $finder */
                $finder = $this->em()->getFinder('KL\EditorManager:GoogleFont');

                $params['fonts'] = $finder->active()->fetch();

                $template = "editor_dialog_kl_em_gfont";
                break;

            default:
                break;
        }

        /* No template catched here, return parent */
        if (is_null($template)) {
            return parent::loadDialog($dialog);
        }

        /* Catched a template, continue with overlay render */
        $data = [
            'dialog' => $dialog,
            'view' => $view,
            'template' => $template,
            'params' => $params
        ];

        $this->app->fire('editor_dialog', [&$data, $this], $dialog);

        return $data;
    }

    /**
     * @return \XF\Mvc\Reply\View
     */
    public function actionKlEmSpecialChars()
    {
        /** @var SpecialChars $repo */
        $repo = $this->repository('KL\EditorManager:SpecialChars');

        $categories = $repo->getCategoriesForList();
        $characters = $repo->getCharactersForList($categories->keys());
        $groupedCharacters = $characters->groupBy('group_id');

        $recent = [];
        $recentlyUsed = $this->request->getCookie('klem_specialcharacter_usage', '');
        if ($recentlyUsed) {
            $recentlyUsed = array_reverse(explode(',', $recentlyUsed));

            foreach ($recentlyUsed AS $id) {
                if ($characters->offsetExists($id)) {
                    $recent[$id] = $characters->offsetGet($id);
                }
            }
        }

        $viewParams = [
            'recent' => $recent,
            'groupedCharacters' => $groupedCharacters,
            'categories' => $categories
        ];

        return $this->view('KL\EditorManager:Editor\SpecialCharacters', 'kl_em_editor_special_characters', $viewParams);
    }

    /**
     * @return \XF\Mvc\Reply\View
     */
    public function actionKlEmSpecialCharsSearch()
    {
        $q = ltrim($this->filter('q', 'str', ['no-trim']));

        if ($q !== '' && utf8_strlen($q) >= 2) {
            /** @var SpecialChars $repo */
            $repo = $this->repository('KL\EditorManager:SpecialChars');

            $results = $repo->getMatchingCharactersByString($q, [
                'limit' => 20
            ]);
        } else {
            $results = [];
            $q = '';
        }

        $viewParams = [
            'q' => $q,
            'results' => $results
        ];
        return $this->view('KL\EditorManager:Editor\SpecialCharacters\Search',
            'kl_em_editor_special_characters_search_results', $viewParams);
    }

    public function actionSmiliesEmoji()
    {
        $response = parent::actionSmiliesEmoji();

        if ($response instanceof View) {
            /** @var Smilie $smilieRepo */
            $smilieRepo = $this->repository('XF:Smilie');

            $smilies = $response->getParam('groupedSmilies');

            foreach ($smilies as &$smilieCategory) {
                $smilieRepo->filterSmilies($smilieCategory);
            }
            $response->setParam('groupedSmilies', $smilies);

            $recent = $response->getParam('recent');
            $smilieRepo->filterSmilies($recent);
            $response->setParam('recent', $recent);
        }

        return $response;
    }
}