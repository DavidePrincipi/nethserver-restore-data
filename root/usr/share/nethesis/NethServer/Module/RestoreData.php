<?php
/*
 * Copyright (C) 2013 Nethesis S.r.l.
 * http://www.nethesis.it - support@nethesis.it
 *
 * This script is part of NethServer.
 *
 * NethServer is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or any later version.
 *
 * NethServer is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with NethServer.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace NethServer\Module;
use Nethgui\System\PlatformInterface as Validate;

class RestoreData extends \Nethgui\Controller\AbstractController implements \Nethgui\Component\DependencyConsumer
{

    protected function initializeAttributes(\Nethgui\Module\ModuleAttributesInterface $base)
    {
        return \Nethgui\Module\SimpleModuleAttributesProvider::extendModuleAttributes($base, 'Configuration', 80);
    }

    // Declare all parameters
    public function initialize()
    {
        parent::initialize();
        $this->declareParameter('path', Validate::NOTEMPTY);
        $this->declareParameter('position', Validate::ANYTHING);
    }

    public function process()
    {

        parent::process();
        if($this->getRequest()->isMutation()) {

            $path = $this->parameters['path'];
            $position = $this->parameters['position'];
            $this->restore_path = $this->getPlatform()->exec('/usr/bin/sudo /usr/libexec/nethserver/nethserver-restore-data-helper ${@}', array($position, $path))->getOutput();
        }
    }

    public function prepareView(\Nethgui\View\ViewInterface $view) {
        $db_file = "/var/cache/restore/duc.db";
        $xml_file = "/var/cache/restore/duc.xml";
        $start_index = "/";
        $tree = array();
        $root = array( 'text' => $start_index, 'children' => array() );

        parent::prepareView($view);

        if($this->getRequest()->isMutation()) {
            if(isset($this->restore_path)) {
                $this->notifications->message($view->translate('RestoreData_restore_message', array($this->restore_path)));
            }
        }

        if($this->getRequest()->hasParameter('base')) {
            if(file_exists($xml_file)) {
                $xml_string = $this->getPhpWrapper()->file_get_contents($xml_file);
            } else {
                $xml_string = $this->getPlatform()->exec("/usr/bin/duc xml --min-size=1 --exclude-files --database=$db_file $start_index")->getOutput();
            }

            $xml_full = simplexml_load_string($xml_string);

            foreach (glob("/etc/backup-data.d/*.include") as $filename) {
                $fileRead = $this->getPhpWrapper()->file_get_contents($filename);
                $tempArr = explode("\n", $fileRead);
                foreach ($tempArr as $k) {
                    $includeArrayDirty[] = $k;
                }
            }

            $includeArray = $this->cleanArray($includeArrayDirty);

            foreach (glob("/etc/backup-data.d/*.exclude") as $filename) {
                $fileRead = $this->getPhpWrapper()->file_get_contents($filename);
                $tempArr = explode("\n", $fileRead);
                foreach ($tempArr as $k) {
                    $excludeArrayDirty[] = $k;
                }
            }

            $excludeArray = $this->cleanArray($excludeArrayDirty);

            $alreadyIncluded = array();

            $tree = $this->filter_xml($xml_full, $root, $start_index, $includeArray, $excludeArray, $alreadyIncluded);

            $view['result'] = $tree;

        } else if($this->getRequest()->hasParameter('start')) {
            $start = $this->getRequest()->getParameter('start');
            $cmd = "/usr/bin/duc xml --min-size=1 --database=$db_file $start";
            $xml_string = $this->getPlatform()->exec($cmd)->getOutput();
            $xml = simplexml_load_string($xml_string);
            $tree = $this->walk_dir_clean($xml, $root);

            $view['start'] = $tree;
        }
    }

    private function filter_xml($dir, &$root, $start, $includeArray, $excludeArray, $alreadyIncluded) {
        
        foreach ($dir->ent as $ent) {
            $name = trim((string)$ent['name']);

            if($start != '/') {
                $fullpath = $start."/".$name;
            } else {
                $fullpath = "/".$name;
            }

            foreach ($includeArray as $path) {

                if(!empty($path)) {
                    if (strpos(trim($fullpath), trim($path)) === 0 || strpos(trim($path), trim($fullpath)) === 0) {
                        $res = str_replace($path, "", $fullpath);
                        if($res[0] === '/' || empty($res[0])) {
                            if(!in_array($fullpath, $excludeArray)) {
                                if(!in_array($fullpath, $alreadyIncluded)) {
                                    $alreadyIncluded[] = $fullpath;
                                    if ( !$ent->count() ) {
                                        $root['children'][] = array( 'text' => $name, 'fullpath' => $fullpath );
                                    } else {
                                        $node = array( 'text' => $name, 'children' => array(), 'fullpath' => $fullpath);
                                        $root['children'][] = $this->filter_xml($ent, $node, $fullpath, $includeArray, $excludeArray, $alreadyIncluded);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return $root;
    }

    private function cleanArray($arr) {
        $cleaned = array();
        foreach ($arr as $k) {
            if(substr($k, -1) == '/') {
                $k = substr($k, 0, -1);
            }
            $cleaned[] = $k;
        }
        return $cleaned;
    }

    private function walk_dir_clean($dir, &$root) {
        foreach ($dir->ent as $ent) {
            $name = trim((string)$ent['name']);

            if ( !$ent->count() ) {
                $root['children'][] = array( 'text' => $name);
            } else {
                $node = array( 'text' => $name, 'children' => array());
                $root['children'][] = $this->walk_dir_clean($ent, $node);
            }
        }
        return $root;
    }

    public function setUserNotifications(\Nethgui\Model\UserNotifications $n)
    {
        $this->notifications = $n;
        return $this;
    }

    public function getDependencySetters()
    {
        return array('UserNotifications' => array($this, 'setUserNotifications'));
    }

}
