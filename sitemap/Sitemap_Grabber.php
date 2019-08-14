<?php
/**
 * Freedom Engine
 * Sitemap_Grabber Class
 *
 * @copyright Copyright (c) 2019
 */

class Sitemap_Grabber extends Base_Grabber
{
    public function __construct($settings = [])
    {
        parent::__construct($settings);
        $this->_clear_tables();
    }

    private function _clear_tables()
    {
        $this->_db->exec('TRUNCATE TABLE `site_donor_sitemap`');
    }

    public function run()
    {
        $this->_import_sites();
        $this->_import_sitemaps();
    }

    private function _import_sites()
    {
        // проверять на открытость стату

        $yxml = new YandexXML;

        $yxml->set_param([
            'user' => '',
            'key' => '',
            'query' => '',
            'lr' => '213',
            'filter' => 'none',
            'groupby' => 'attr="".mode=flat.groups-on-page=100.docs-in-group=1',
            'page' => '10'
        ]);

        $groups = null;

        while (!isset($groups))
        {
            $xml = $yxml->request();

            if (isset($xml->response->results->grouping->group))
                $groups = $xml->response->results->grouping->group;

            if (sizeof($groups) > 0)
            {
                foreach ($groups as $group)
                {
                    $docs = $group->doc;

                    if (sizeof($docs) > 0)
                    {
                        foreach ($docs as $doc)
                        {
                            $url_data = parse_url((string) $doc->url);

                            $data = [
                                'title' => mb_strtolower((string) $doc->domain),
                                'protocol_type_id' => ($url_data['scheme'] == 'https' ? 2 : 1),
                                'domain' => mb_strtolower((string) $doc->domain),
                            ];

                            $this->_import_site($data);
                        }
                    }
                }
            }
        }
    }

    private function _import_sitemaps()
    {
        $start = microtime(true); // test
        $sitemap = [];
        $sites = $this->_get_sites();
        shuffle($sites);

        foreach ($sites as $site)
        {
            sleep(rand(0, 3));
            echo $site['domain'] . "\r\n"; // test

            $this->_ssl = $site['protocol_type_id'] ? 0 : 1;
            $base_url = ($this->_ssl ? 'https://' : 'http://') . $site['domain'];
            $url = $base_url . '/sitemap.xml';
            $sitemap = $this->_get_sitemap($site['id'], $url);

            if (empty($sitemap)) continue;

            $this->_import_sitemap($sitemap);
        }

        echo 'Runtime:' . get_runtime($start) . "\r\n"; // test
    }
}