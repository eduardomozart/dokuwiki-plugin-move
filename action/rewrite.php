<?php
/**
 * Move Plugin Page Rewrite Functionality
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

/**
 * Class action_plugin_move_rewrite
 */
class action_plugin_move_rewrite extends DokuWiki_Action_Plugin {

    /**
     * Register event handlers.
     *
     * @param Doku_Event_Handler $controller The plugin controller
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('IO_WIKIPAGE_READ', 'AFTER', $this, 'handle_read', array());
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'handle_cache', array());
        $controller->register_hook('PARSER_METADATA_RENDER', 'AFTER', $this, 'handle_metadata', array());
    }

    /**
     * Rewrite pages when they are read and they need to be updated.
     *
     * @param Doku_Event $event The event object
     * @param mixed      $param Optional parameters (not used)
     */
    function handle_read(Doku_Event $event, $param) {
        global $ACT, $conf;
        static $stack = array();
        // handle only reads of the current revision
        if($event->data[3]) return;

        // only rewrite if not in move already
        $save = true;
        if(helper_plugin_move_rewrite::isLocked()) {
            $save = false;
        }

        $id = $event->data[2];
        if($event->data[1]) $id = $event->data[1] . ':' . $id;

        if(!$id) {
            // try to reconstruct the id from the filename
            $path = $event->data[0][0];
            if(strpos($path, $conf['datadir']) === 0) {
                $path = substr($path, strlen($conf['datadir']) + 1);
                $id   = pathID($path);
            }
        }

        if(isset($stack[$id])) return;

        // Don't change the page when the user is currently changing the page content or the page is locked
        $forbidden_actions = array('save', 'preview', 'recover', 'revert');
        if((isset($ACT) && (
                    in_array($ACT, $forbidden_actions) || (is_array($ACT) && in_array(key($ACT), $forbidden_actions)
                    )))
            // checklock checks if the page lock hasn't expired and the page hasn't been locked by another user
            // the file exists check checks if the page is reported unlocked if a lock exists which means that
            // the page is locked by the current user
            || checklock($id) !== false || @file_exists(wikiLockFN($id))
        ) return;

        /** @var helper_plugin_move_rewrite $helper */
        $helper = plugin_load('helper', 'move_rewrite', true);
        if(!is_null($helper)) {
            $stack[$id]    = true;
            $event->result = $helper->rewritePage($id, $event->result, $save);
            unset($stack[$id]);
        }
    }

    /**
     * Handle the cache events, it looks if a page needs to be rewritten so it can expire the cache of the page
     *
     * @param Doku_Event $event The even object
     * @param mixed      $param Optional parameters (not used)
     */
    function handle_cache(Doku_Event $event, $param) {
        global $conf;
        /** @var $cache cache_parser */
        $cache = $event->data;
        $id    = $cache->page;
        if(!$id) {
            // try to reconstruct the id from the filename
            $path = $cache->file;
            if(strpos($path, $conf['datadir']) === 0) {
                $path = substr($path, strlen($conf['datadir']) + 1);
                $id   = pathID($path);
            }
        }
        if($id) {
            /** @var helper_plugin_move_rewrite $helper */
            $helper = $this->loadHelper('move_rewrite');
            if(!is_null($helper)) {
                $meta = $helper->getMoveMeta($id);
                if($meta && ($meta['pages'] || $meta['media'])) {
                    $file = wikiFN($id, '', false);
                    if(is_writable($file))
                        $cache->depends['purge'] = true;
                    else // FIXME: print error here or fail silently?
                        msg('Error: Page ' . hsc($id) . ' needs to be rewritten because of page renames but is not writable.', -1);
                }
            }
        }
    }

    /**
     * Add media and page links from local interwiki syntax (e.g. this>lib/exe/fetch.php?media=...)
     * to the page metadata relations so DokuWiki's search indexer tracks them.
     *
     * @param Doku_Event $event The event object
     * @param mixed      $param Optional parameters (not used)
     */
    public function handle_metadata(Doku_Event $event, $param) {
        if (!isset($event->data['page']) || !isset($event->result['current'])) {
            return;
        }

        $id = $event->data['page'];
        $instructions = p_cached_instructions(wikiFN($id), false, $id);
        if (!$instructions) return;

        /** @var helper_plugin_move_handler $handler */
        $handler = plugin_load('helper', 'move_handler');

        foreach ($instructions as $ins) {
            if ($ins[0] === 'interwikilink') {
                $wiki = $ins[1][2];
                $uri  = $ins[1][3];

                $isLocal = $handler
                    ? $handler->isLocalInterwiki($wiki)
                    : (strtolower($wiki) === 'this');

                if ($isLocal) {
                    if (!isset($event->result['current']['relation']['media'])) {
                        $event->result['current']['relation']['media'] = [];
                    }
                    if (!isset($event->result['current']['relation']['references'])) {
                        $event->result['current']['relation']['references'] = [];
                    }

                    // 1. fetch.php / detail.php with ?media=
                    if (preg_match('/(?:lib\/exe\/)?(?:fetch|detail)\.php\?[^#]*\bmedia=([^&#|]+)/i', $uri, $m)) {
                        $media = cleanID(urldecode($m[1]));
                        if ($media !== '') {
                            $event->result['current']['relation']['media'][$media] = file_exists(mediaFN($media));
                        }
                    // 2. fetch.php / detail.php with PATH_INFO
                    } elseif (preg_match('/(?:lib\/exe\/)?(?:fetch|detail)\.php\/([^?#|]+)/i', $uri, $m)) {
                        $media = cleanID(urldecode($m[1]));
                        if ($media !== '') {
                            $event->result['current']['relation']['media'][$media] = file_exists(mediaFN($media));
                        }
                    // 3. _media / _detail
                    } elseif (preg_match('/^_(?:media|detail)\/([^?#|]+)/i', $uri, $m)) {
                        $media = cleanID(urldecode($m[1]));
                        if ($media !== '') {
                            $event->result['current']['relation']['media'][$media] = file_exists(mediaFN($media));
                        }
                    // 4. doku.php?id=
                    } elseif (preg_match('/(?:lib\/exe\/)?doku\.php\?[^#]*\bid=([^&#|]+)/i', $uri, $m)) {
                        $page = cleanID(urldecode($m[1]));
                        if ($page !== '') {
                            $event->result['current']['relation']['references'][$page] = page_exists($page);
                        }
                    // 5. doku.php/ PATH_INFO
                    } elseif (preg_match('/(?:lib\/exe\/)?doku\.php\/([^?#|]+)/i', $uri, $m)) {
                        $page = cleanID(urldecode($m[1]));
                        if ($page !== '') {
                            $event->result['current']['relation']['references'][$page] = page_exists($page);
                        }
                    }
                }
            }
        }
    }
}
