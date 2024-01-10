<?php
/**
 * @version     sw.build.version
 * @copyright   Copyright (C) 2019 - 2023 Sven Schultschik. All rights reserved
 * @license     GPL-3.0-or-later
 * @author      Sven Schultschik (extensions@schultschik.de)
 * @link        extensions.schultschik.de
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Utilities\ArrayHelper;
use Kunena\Forum\Libraries\Factory\KunenaFactory;
use Kunena\Forum\Libraries\Forum\Category\KunenaCategoryHelper;
use Kunena\Forum\Libraries\Forum\Topic\KunenaTopicHelper;
use Kunena\Forum\Libraries\Route\KunenaRoute;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Uri\Uri;

/** Handles Kunena forum structure */
class schuweb_sitemap_kunena
{
    static $profile;
    static $config;

    /**
     * ItemID of the top Kunena menu item
     * 
     * @since 5.0.1
     */
    static $topItemID;

    /**
     * @param \SchuWeb\Component\Sitemap\Site\Model\SitemapModel $sitemap
     * @param \stdClass $parent
     * @param \Joomla\Registry\Registry $params
     * 
     */
    static function getTree(&$sitemap, &$parent, &$params)
    {
        // This component does not provide news content.
        // An image sitemap does not make sense, hence those are community postings
        // don't waste time/resources
        if ($sitemap->isNewssitemap() || $sitemap->isImagesitemap())
            return false;

        // Make sure that we can load the kunena api
        if (!self::loadKunenaApi())
            return false;

        if (!self::$profile) {
            self::$config = KunenaFactory::getConfig();
            self::$profile = KunenaFactory::getUser();
        }

        if (is_null(self::$topItemID))
            self::$topItemID = $parent->id;

        $user = Factory::getApplication()->getIdentity();

        $link_query = parse_url($parent->link);
        if (!isset($link_query['query'])) {
            return;
        }

        parse_str(html_entity_decode($link_query['query']), $link_vars);
        $view = ArrayHelper::getValue($link_vars, 'view', '');

        switch ($view) {
            case 'showcat':
            case 'category':
                $link_query = parse_url($parent->link);
                parse_str(html_entity_decode($link_query['query']), $link_vars);
                $catid = ArrayHelper::getValue($link_vars, 'catid', 0);
                break;
            case 'listcat':
            case 'entrypage':
                $catid = 0;
                break;
            default:
                return true; // Do not expand links to posts
        }

        $include_topics = ArrayHelper::getValue($params, 'include_topics', 1);
        $include_topics = ($include_topics == 1
            || ($include_topics == 2 && $sitemap->isXmlsitemap())
            || ($include_topics == 3 && !$sitemap->isXmlsitemap()));
        $params['include_topics'] = $include_topics;

        $priority = ArrayHelper::getValue($params, 'cat_priority', $parent->priority);
        $changefreq = ArrayHelper::getValue($params, 'cat_changefreq', $parent->changefreq);
        if ($priority == '-1')
            $priority = $parent->priority;
        if ($changefreq == '-1')
            $changefreq = $parent->changefreq;

        $params['cat_priority'] = $priority;
        $params['cat_changefreq'] = $changefreq;
        $params['groups'] = implode(',', $user->getAuthorisedViewLevels());

        $priority = ArrayHelper::getValue($params, 'topic_priority', $parent->priority);
        $changefreq = ArrayHelper::getValue($params, 'topic_changefreq', $parent->changefreq);
        if ($priority == '-1')
            $priority = $parent->priority;

        if ($changefreq == '-1')
            $changefreq = $parent->changefreq;

        $params['topic_priority'] = $priority;
        $params['topic_changefreq'] = $changefreq;

        if ($include_topics) {
            $ordering = ArrayHelper::getValue($params, 'topics_order', 'ordering');
            if (!in_array($ordering, array('id', 'ordering', 'time', 'subject', 'hits')))
                $ordering = 'ordering';
            $params['topics_order'] = 't.`' . $ordering . '`';
            $params['include_pagination'] = $sitemap->isXmlsitemap();

            $params['limit'] = 0;
            $params['days'] = '';
            $limit = ArrayHelper::getValue($params, 'max_topics', '');
            if (intval($limit))
                $params['limit'] = $limit;

            $days = ArrayHelper::getValue($params, 'max_age', '');
            $params['days'] = false;
            if (intval($days))
                $params['days'] = ($sitemap->now - (intval($days) * 86400));
        }

        $params['table_prefix'] = '#__kunena';

        self::getCategoryTree($sitemap, $parent, $params, $catid);
    }

    /*
     * Builds the Kunena's tree
     */
    static function getCategoryTree(&$sitemap, &$parent, &$params, &$parentCat)
    {
        // Load categories
        $categories = KunenaCategoryHelper::getChildren($parentCat);

        /* get list of categories */
        foreach ($categories as $cat) {
            $node = new stdclass;
            $node->id = $parent->id;
            $node->browserNav = $parent->browserNav;
            $id = $node->uid = 'com_kunenac' . $cat->id;
            $node->name = $cat->name;
            $node->priority = $params['cat_priority'];
            $node->changefreq = $params['cat_changefreq'];
            $node->browserNav = $parent->browserNav;
            $node->xmlInsertChangeFreq = $parent->xmlInsertChangeFreq;
            $node->xmlInsertPriority = $parent->xmlInsertPriority;

            $node->link = KunenaRoute::normalize(
                new Uri(
                    (string) 'index.php?option=com_kunena&view=category&catid=' . $cat->id
                )
            );
            self::checkItemID($node->link);
            $node->expandible = true;
            $node->secure = $parent->secure;

            $node->lastmod = $parent->lastmod;
            $node->modified = intval($cat->last_post_time);

            if (!isset($parent->subnodes))
                $parent->subnodes = new \stdClass();

            $node->params = &$parent->params;

            $parent->subnodes->$id = $node;

            self::getTopics($sitemap, $node, $params, $cat->id);

            self::getCategoryTree($sitemap, $parent, $params, $cat->id);
        }
    }

    /**
     * Check if the given link has an Itemid. Kunena does this for the frontend
     * but the XML is generated in the backend and there the Itemid is not be
     * added by Kunena
     * 
     * @param string $link
     * 
     * @since 5.0.1
     */
    static function checkItemID(&$link){
        if (!str_contains($link, 'Itemid'))
            $link = KunenaRoute::normalize(
                new Uri(
                    (string) $link . '&Itemid=' . self::$topItemID
                )
            );
    }

    /**
     * Include the topics of the carrying category
     * 
     * @param \SchuWeb\Component\Sitemap\Site\Model\SitemapModel $sitemap
     * @param \stdClass $parent
     * @param \Joomla\Registry\Registry $params
     * @param int $parentCat
     * 
     * @since 5.0.1
     */
    static function getTopics(&$sitemap, &$parent, &$params, &$parentCat){

        if ($params['include_topics']) {

            // TODO: orderby parameter is missing:
            $tparams = array();
            if ($params['days'] != '')
                $tparams['starttime'] = $params['days'];
            if ($params['limit'] < 1)
                $tparams['nolimit'] = true;

            $topics = KunenaTopicHelper::getLatestTopics($parentCat, 0, $params['limit'], $tparams);
            if (count($topics) == 2 && is_numeric($topics[0])) {
                $topics = $topics[1];
            }

            //get list of topics
            foreach ($topics as $topic) {
                $node = new stdclass;
                $node->id = $parent->id;
                $node->browserNav = $parent->browserNav;
                $id = $node->uid = 'com_kunenat' . $topic->id;
                $node->name = $topic->subject;
                $node->priority = $params['topic_priority'];
                $node->changefreq = $params['topic_changefreq'];

                $node->browserNav = $parent->browserNav;

                $node->xmlInsertChangeFreq = $parent->xmlInsertChangeFreq;
                $node->xmlInsertPriority = $parent->xmlInsertPriority;

                $node->modified = intval(@$topic->last_post_time ? $topic->last_post_time : $topic->time);
                $node->link = KunenaRoute::normalize(
                    new URI(
                        (string) 'index.php?option=com_kunena&view=topic&catid='
                        . $topic->category_id . '&id=' . $topic->id
                    )
                );
                self::checkItemID($node->link);
                $node->expandible = false;
                $node->secure = $parent->secure;
                $node->lastmod = $parent->lastmod;

                if (!isset($parent->subnodes))
                    $parent->subnodes = new \stdClass();

                // Pagination will not work with K2.0, revisit this when that version is out and stable
                if ($params['include_pagination'] && isset($topic->msgcount) && $topic->msgcount > self::$config->messagesPerPage) {
                    $msgPerPage = self::$config->messagesPerPage;
                    $threadPages = ceil($topic->msgcount / $msgPerPage);
                    for ($i = 2; $i <= $threadPages; $i++) {
                        $subnode = new stdclass;
                        $subnode->id = $node->id;
                        $id = $subnode->uid = $node->uid . 'p' . $i;
                        $subnode->name = "[$i]";
                        $subnode->seq = $i;
                        $subnode->link = $node->link . '&limit=' . $msgPerPage . '&limitstart=' . (($i - 1) * $msgPerPage);
                        $subnode->browserNav = $node->browserNav;
                        $subnode->priority = $node->priority;
                        $subnode->changefreq = $node->changefreq;

                        $subnode->xmlInsertChangeFreq = $node->xmlInsertChangeFreq;
                        $subnode->xmlInsertPriority = $node->xmlInsertPriority;

                        $subnode->modified = $node->modified;
                        $subnode->secure = $node->secure;
                        $subnode->lastmod = $node->lastmod;

                        if (!isset($node->subnodes))
                            $node->subnodes = new \stdClass();

                        $node->subnodes->$id = $subnode;
                    }
                }

                $parent->subnodes->$id = $node;
            }
        }
    }

    private static function loadKunenaApi()
    {
        if (!defined('KUNENA_LOADED')) {
            jimport('joomla.application.component.helper');
            // Check if Kunena component is installed/enabled
            if (!ComponentHelper::isEnabled('com_kunena')) {
                return false;
            }

            // Check if Kunena API exists
            $kunena_api = JPATH_ADMINISTRATOR . '/components/com_kunena/api.php';
            if (!is_file($kunena_api))
                return false;

            // Load Kunena API
            require_once($kunena_api);
        }
        return true;
    }
}
