<?php
/**
 * @version     sw.build.version
 * @copyright   Copyright (C) 2019 - 2025 Sven Schultschik. All rights reserved
 * @license     GPL-3.0-or-later
 * @author      Sven Schultschik (extensions@schultschik.de)
 * @link        extensions.schultschik.de
 */

namespace SchuWeb\Plugin\SchuWeb_Sitemap\Kunena\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Utilities\ArrayHelper;
use Kunena\Forum\Libraries\Factory\KunenaFactory;
use Kunena\Forum\Libraries\Forum\Category\KunenaCategoryHelper;
use Kunena\Forum\Libraries\Forum\Topic\KunenaTopicHelper;
use Kunena\Forum\Libraries\Route\KunenaRoute;
use Joomla\Uri\Uri;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use SchuWeb\Component\Sitemap\Site\Event\TreePrepareEvent;

class Kunena extends CMSPlugin implements SubscriberInterface
{
    /**
     * @since 5.2.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onGetTree'  => 'onGetTree',
        ];
    }

    /**
     * ItemID of the top Kunena menu item
     * 
     * @since 5.0.1
     */
    private $topItemID;

     /**
     * Expands a com_content menu item
     *
     * @param   TreePrepareEvent  Event object
     *
     * @return void
     * @since  5.2.0
     */
    public function onGetTree(TreePrepareEvent $event)
    {
        $sitemap = $event->getSitemap();
        $parent  = $event->getNode();

        if ($parent->option != "com_kunena")
            return null;

        // This component does not provide news content.
        // An image sitemap does not make sense, hence those are community postings
        // don't waste time/resources
        if ($sitemap->isNewssitemap() || $sitemap->isImagesitemap())
            return null;

        // Make sure that we can load the kunena api
        if (!self::isKunenaActiveAndLoaded())
            return null;

        if (is_null($this->topItemID))
            $this->topItemID = $parent->id;

        $user = Factory::getApplication()->getIdentity();
        if (is_null($user))
            $groups = [0 => 1];
        else
            $groups = $user->getAuthorisedViewLevels();

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
                return null; // Do not expand links to posts
        }

        $include_topics = $this->params->get('include_topics', 1);
        $include_topics = ($include_topics == 1
            || ($include_topics == 2 && $sitemap->isXmlsitemap())
            || ($include_topics == 3 && !$sitemap->isXmlsitemap()));
        $params['include_topics'] = $include_topics;

        $priority = $this->params->get('cat_priority', $parent->priority);
        $changefreq = $this->params->get('cat_changefreq', $parent->changefreq);
        if ($priority == '-1')
            $priority = $parent->priority;
        if ($changefreq == '-1')
            $changefreq = $parent->changefreq;

        $params['cat_priority'] = $priority;
        $params['cat_changefreq'] = $changefreq;
        $params['groups'] = implode(',', $groups);

        $priority = $this->params->get( 'topic_priority', $parent->priority);
        $changefreq = $this->params->get('topic_changefreq', $parent->changefreq);
        if ($priority == '-1')
            $priority = $parent->priority;

        if ($changefreq == '-1')
            $changefreq = $parent->changefreq;

        $params['topic_priority'] = $priority;
        $params['topic_changefreq'] = $changefreq;

        if ($include_topics) {
            $ordering = $this->params->get( 'topics_order', 'ordering');
            if (!in_array($ordering, array('id', 'ordering', 'time', 'subject', 'hits')))
                $ordering = 'ordering';
            $params['topics_order'] = 't.`' . $ordering . '`';
            $params['include_pagination'] = $sitemap->isXmlsitemap();

            $params['limit'] = 0;
            $params['days'] = '';
            $limit = $this->params->get( 'max_topics', '');
            if (intval($limit))
                $params['limit'] = $limit;

            $days = $this->params->get( 'max_age', '');
            $params['days'] = false;
            if (intval($days))
                $params['days'] = ($sitemap->now - (intval($days) * 86400));
        }

        $params['table_prefix'] = '#__kunena';

        $this->getCategoryTree($sitemap, $parent, $params, $catid);
    }

    private function getCategoryTree(&$sitemap, &$parent, &$params, &$parentCat)
    {
        $categories = KunenaCategoryHelper::getChildren($parentCat);

        foreach ($categories as $cat) {
            $node = new \stdClass();
            $node->id = $parent->id;
            $node->browserNav = $parent->browserNav;
            $id = $node->uid = 'com_kunenac' . $cat->id;
            $node->name = $cat->name;
            $node->priority = $params['cat_priority'];
            $node->changefreq = $params['cat_changefreq'];
            $node->browserNav = $parent->browserNav;

            $node->link = KunenaRoute::normalize(
                new Uri(
                    (string) 'index.php?option=com_kunena&view=category&catid=' . $cat->id
                )
            );
            $this->checkItemID($node->link);
            $node->expandible = true;
            $node->secure = $parent->secure;

            $node->modified = intval($cat->last_post_time);

            if (!isset($parent->subnodes))
                $parent->subnodes = new \stdClass();

            $node->params = &$parent->params;

            $parent->subnodes->$id = $node;

            $this->getTopics($sitemap, $node, $params, $cat->id);

            $this->getCategoryTree($sitemap, $parent, $params, $cat->id);
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
    private function checkItemID(&$link){
        if (!str_contains($link, 'Itemid'))
            $link = KunenaRoute::normalize(
                new Uri(
                    (string) $link . '&Itemid=' . $this->topItemID
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
    private function getTopics(&$sitemap, &$parent, &$params, &$parentCat){

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

            $config = KunenaFactory::getConfig();
            $msgPerPage = $config->messagesPerPage;

            foreach ($topics as $topic) {
                $node = new \stdClass;
                $node->id = $parent->id;
                $node->browserNav = $parent->browserNav;
                $id = $node->uid = 'com_kunenat' . $topic->id;
                $node->name = $topic->subject;
                $node->priority = $params['topic_priority'];
                $node->changefreq = $params['topic_changefreq'];

                $node->browserNav = $parent->browserNav;

                $node->modified = intval(@$topic->last_post_time ? $topic->last_post_time : $topic->time);
                $node->link = KunenaRoute::normalize(
                    new URI(
                        (string) 'index.php?option=com_kunena&view=topic&catid='
                        . $topic->category_id . '&id=' . $topic->id
                    )
                );

                $this->checkItemID($node->link);
                
                $node->expandible = false;
                $node->secure = $parent->secure;

                if (!isset($parent->subnodes))
                    $parent->subnodes = new \stdClass();

                // Pagination will not work with K2.0, revisit this when that version is out and stable
                if ($params['include_pagination'] && isset($topic->msgcount) && $topic->msgcount > $msgPerPage) {
                    $threadPages = ceil($topic->msgcount / $msgPerPage);
                    for ($i = 2; $i <= $threadPages; $i++) {
                        $subnode = new \stdclass;
                        $subnode->id = $node->id;
                        $id = $subnode->uid = $node->uid . 'p' . $i;
                        $subnode->name = "[$i]";
                        $subnode->seq = $i;
                        $subnode->link = $node->link . '&limit=' . $msgPerPage . '&limitstart=' . (($i - 1) * $msgPerPage);
                        $subnode->browserNav = $node->browserNav;
                        $subnode->priority = $node->priority;
                        $subnode->changefreq = $node->changefreq;

                        $subnode->modified = $node->modified;
                        $subnode->secure = $node->secure;

                        if (!isset($node->subnodes))
                            $node->subnodes = new \stdClass();

                        $node->subnodes->$id = $subnode;
                    }
                }

                $parent->subnodes->$id = $node;
            }
        }
    }

    private static function isKunenaActiveAndLoaded()
    {
       return !defined('KUNENA_LOADED') ? false : true;
    }
}
