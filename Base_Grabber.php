<?php
/**
 * Freedom Engine
 * Base_Importer Class
 *
 * @copyright Copyright (c) 2019
 */

class Base_Grabber
{
    protected $_settings = [];
    protected $_db = null;
    protected $_id = null;
    protected $_curl = null;
    protected $_ssl = null;

    public function __construct($settings = [])
    {
        $this->_settings = $settings;
        $this->_db = new PDO($this->_settings['db']['dsn'], $this->_settings['db']['db_user'], $this->_settings['db']['db_password']);
    }

    protected function _set_details($entity)
    {
        $rows = $this->_db->query('SELECT * FROM ' . $entity . '_details')->fetchAll(PDO::FETCH_ASSOC);

        $var = $entity . '_details';

        foreach ($rows as $row)
        {
            if (isset($row['source_id']))
                $this->{$var}[$row['type_id']][$row['source_id']][$row['name']] = $row;
            else
                $this->{$var}[$row['type_id']][$row['name']] = $row;
        }
    }

    protected function _get_details($entity, $type_id, $source_id = 0)
    {
        $var = $entity . '_details';

        if ($source_id)
            $details = (isset($this->{$var}[$type_id][$source_id]) ? $this->{$var}[$type_id][$source_id] : []);
        else
            $details = (isset($this->{$var}[$type_id]) ? $this->{$var}[$type_id] : []);

        return $details;
    }

    protected function _get_sites($limit = 0)
    {
        $sites = [];

        $sth = $this->_db->prepare("SELECT * FROM `sites_donors` WHERE `parser` = 1 AND `available` = 1 AND `approved` = 1 AND `enabled` = 1" . ($limit ? ' LIMIT ' . $limit : ''));
        $sth->execute();

        $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row)
            $sites[$row['id']] = $row;

        return $sites;
    }

    protected function _import_site($data)
    {
        $sth = $this->_db->prepare("
          SELECT
            `id`
          FROM
             `sites_donors`
          WHERE
            `domain` = :domain
        ");

        $sth->execute([
            'domain' => $data['domain']
        ]);

        if ($sth->fetchColumn()) return 0;

        $st_sites_donors_insert = $this->_db->prepare("
          INSERT INTO
            sites_donors (`title`, `domain`, `protocol_type_id`, `pages_count`, `cms`, `blocked`, `block_type_id`, `available`, `approved`, `excluded`, `enabled`, `date_added`)
          VALUES
            (:title, :domain, :protocol_type_id, :pages_count, :cms, :blocked, :block_type_id, :available, :approved, :excluded, :enabled, NOW())	        
        ");

        $st_sites_donors_insert->execute([
            'title' => $data['title'],
            'domain' => $data['domain'],
            'protocol_type_id' => $data['protocol_type_id'],
            'pages_count' => 0,
            'cms' => '',
            'blocked' => 0,
            'block_type_id' => 0,
            'available' => 1,
            'approved' => 0,
            'excluded' => 0,
            'enabled' => 0
        ]);

        return $this->_db->lastInsertId();
    }

    protected function _get_sitemap($site_id, $url)
    {
        $sitemap = [];
        $response = $this->_get_response($url);
        $xml = @simplexml_load_string($response);

        if ($xml === false) return $sitemap;

        $urls = json_decode(json_encode($xml), true);

        if (!isset($urls['url'])) return $sitemap;

        foreach ($urls['url'] as $url)
        {
            $url_data = parse_url(trim($url['loc']));
            $slug = substr($url_data['path'], 1);

            $sitemap[] = [
                'site_id_slug' => md5($site_id . $slug),
                'site_id'   => $site_id,
                'slug'      => $slug,
                'lastmod'   => isset($url['lastmod']) ? $url['lastmod'] : null,
                'date_created' => date("Y-m-d H:i:s")
            ];
        }

        return $sitemap;
    }

    protected function _import_sitemap($data)
    {
        $this->_multi_insert('site_donor_sitemap', $data, true);
    }

    protected function _get_items($item_type_id)
    {
        $sth = $this->_db->prepare("SELECT * FROM `items` WHERE `type_id` = ? AND `level` = 0 AND DATE(`date_updated`) = CURDATE()");
        $sth->execute([$item_type_id]);
        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function _get_details_data($entity, $type_id, $entity_id, $source_id = 0, $details = [])
    {
        $entity_details = $this->_get_details($entity, $type_id, $source_id);

        $details_w_key_sql = '';
        $details_w_o_key_sql = '';
        $details_w_key_first = true;
        $details_w_o_key_first = true;

        $details_table = $this->_get_details_table($entity);

        foreach ($entity_details as $detail_name => $detail_desc)
        {
            if (!empty($details) && !in_array($detail_name, $details)) continue;

            if ($detail_desc['key'])
            {
                $details_w_key_sql .= ($details_w_key_first ? '' : " UNION ") .
                    "SELECT " .
                    "'" . $detail_name . "' AS detail_name, t.id, t.name
                    FROM
                        $details_table id
                    INNER JOIN " .
                    $detail_desc['table'] . " t ON (id.`int_value` = t.`id`)
                    WHERE
                        id." . $entity . "_id = " . $entity_id . " AND id.detail_id = {$entity_details[$detail_name]['id']}";

                $details_w_key_first = false;
            }
            else
            {
                $details_w_o_key_sql .= ($details_w_o_key_first ? '' : " UNION ") .
                    "SELECT " .
                    "'" . $detail_name . "' AS detail_name, id, " . $detail_desc['value_type'] . "_value AS value
                    FROM
                        $details_table
                    WHERE " .
                    $entity . "_id = " . $entity_id . " AND detail_id = {$entity_details[$detail_name]['id']}";

                $details_w_o_key_first = false;
            }
        }

        $sth = $this->_db->query($details_w_key_sql);
        $details_w_key = $sth->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_GROUP);
        $sth = $this->_db->query($details_w_o_key_sql);
        $details_w_o_key = $sth->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

        return array_merge($details_w_o_key, $details_w_key);
    }

    protected function _get_details_table($entity)
    {
        $table = '';

        switch ($entity)
        {
            case 'item':
                $table = 'items_details';
                break;
            case 'video':
                $table = 'videos_details';
                break;
            case 'image':
                $table = 'images_details';
                break;
        }

        return $table;
    }

    protected function _update_donor_sitemap_item($sitemap_item_id, $page_id = 0)
    {
        $st_item_update = $this->_db->prepare("UPDATE `site_donor_sitemap` SET `page_id` = :page_id, `date_checked` = NOW() WHERE `id` = :sitemap_item_id ");
        $st_item_update->execute([
            'page_id' => $page_id,
            'sitemap_item_id' => $sitemap_item_id,
        ]);
    }

    protected function _add_donor_sitemap_item_log($sitemap_item_id_item_id, $sitemap_item_id, $item_id)
    {
        $st_item_insert = $this->_db->prepare("
            INSERT IGNORE INTO `site_donor_sitemap_log`
              (`sitemap_item_id_item_id`, `sitemap_item_id`, `item_id`, `date_checked`)
            VALUES
              (:sitemap_item_id_item_id, :sitemap_item_id, :item_id, NOW())
        ");

        $st_item_insert->execute([
            'sitemap_item_id_item_id' => $sitemap_item_id_item_id,
            'sitemap_item_id' => $sitemap_item_id,
            'item_id' => $item_id
        ]);
    }

    protected function _get_response($url, $ssl = 0)
    {
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3',
            'User-Agent: ' . random_user_agent()
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        //curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        curl_setopt($ch, CURLOPT_PROXY, random_proxy());
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, '');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->_ssl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->_ssl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode == 404)
        {   curl_close($ch);
            return 404;
        }

        curl_close($ch);
        return $response;
    }

    protected function _multi_insert($table_name, $data = [], $ignore = false)
    {
        if (empty($data)) return false;

        $rows_sql = [];
        $to_bind = [];

        $column_names = array_keys($data[0]);

        foreach ($data as $array_index => $row)
        {
            $params = [];

            foreach ($row as $column_name => $column_value)
            {
                $param = ":" . $column_name . $array_index;
                $params[] = $param;
                $to_bind[$param] = $column_value;
            }

            $rows_sql[] = "(" . implode(", ", $params) . ")";
        }

        $sql = "INSERT " . ($ignore ? 'IGNORE' : '') . " INTO `$table_name` (" . implode(", ", $column_names) . ") VALUES " . implode(", ", $rows_sql);

        $this->_db->beginTransaction();

        $pdo_statement = $this->_db->prepare($sql);

        foreach ($to_bind as $param => $val)
            $pdo_statement->bindValue($param, $val);

        $pdo_statement->execute();
        return $this->_db->commit();
    }
}
