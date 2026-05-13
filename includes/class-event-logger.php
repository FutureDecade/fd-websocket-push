<?php
/**
 * WebSocket事件日志记录器
 * 记录所有推送事件的详细信息，用于调试和监控
 */

if (!defined('ABSPATH')) {
    exit;
}

class FD_WebSocket_Push_Event_Logger {
    
    /**
     * 数据库表名
     */
    private $table_name;
    
    /**
     * 构造函数
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'websocket_events';
        
        // 注册激活钩子
        register_activation_hook(FD_WEBSOCKET_PUSH_PLUGIN_FILE, array($this, 'create_table'));

        $this->maybe_upgrade_table();
    }

    /**
     * Create or upgrade the table when the plugin code changes.
     */
    private function maybe_upgrade_table() {
        $schema_version = get_option('fd_websocket_push_event_logger_schema_version', '');

        if (!$this->table_exists() || $schema_version !== FD_WEBSOCKET_PUSH_VERSION) {
            $this->create_table();
            update_option('fd_websocket_push_event_logger_schema_version', FD_WEBSOCKET_PUSH_VERSION, false);
        }
    }
    
    /**
     * 创建事件日志表
     */
    public function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            trace_id varchar(80) DEFAULT NULL,
            event_type varchar(100) NOT NULL,
            event_data longtext,
            target_room varchar(100),
            status varchar(20) DEFAULT 'pending',
            response_data longtext,
            error_message text,
            duration_ms int(11) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trace_id (trace_id),
            KEY event_type (event_type),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        FD_WebSocket_Push_Helper::log('[Event Logger] Database table created: ' . $this->table_name);
    }
    
    /**
     * 记录事件
     */
    public function log_event($event_type, $event_data, $target_room = 'public', $trace_id = null) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'trace_id' => $trace_id,
                'event_type' => $event_type,
                'event_data' => json_encode($event_data, JSON_UNESCAPED_UNICODE),
                'target_room' => $target_room,
                'status' => 'pending'
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            FD_WebSocket_Push_Helper::log('[Event Logger] Failed to log event: ' . $wpdb->last_error, 'ERROR');
            return false;
        }
        
        $event_id = $wpdb->insert_id;
        FD_WebSocket_Push_Helper::log("[Event Logger] Event logged: ID={$event_id}, Trace={$trace_id}, Type={$event_type}, Room={$target_room}");
        
        return $event_id;
    }

    /**
     * Update the stored event payload after the database id is known.
     */
    public function update_event_data($event_id, $event_data) {
        global $wpdb;

        $result = $wpdb->update(
            $this->table_name,
            array(
                'event_data' => json_encode($event_data, JSON_UNESCAPED_UNICODE),
                'updated_at' => current_time('mysql')
            ),
            array('id' => $event_id),
            array('%s', '%s'),
            array('%d')
        );

        if ($result === false) {
            FD_WebSocket_Push_Helper::log('[Event Logger] Failed to update event data: ' . $wpdb->last_error, 'ERROR');
            return false;
        }

        return true;
    }
    
    /**
     * 更新事件状态
     */
    public function update_event_status($event_id, $status, $response_data = null, $error_message = null, $duration_ms = null) {
        global $wpdb;
        
        $update_data = array(
            'status' => $status,
            'updated_at' => current_time('mysql')
        );
        $update_formats = array('%s', '%s');
        
        if ($response_data !== null) {
            $update_data['response_data'] = json_encode($response_data, JSON_UNESCAPED_UNICODE);
            $update_formats[] = '%s';
        }
        
        if ($error_message !== null) {
            $update_data['error_message'] = $error_message;
            $update_formats[] = '%s';
        }

        if ($duration_ms !== null) {
            $update_data['duration_ms'] = intval($duration_ms);
            $update_formats[] = '%d';
        }
        
        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => $event_id),
            $update_formats,
            array('%d')
        );
        
        if ($result === false) {
            FD_WebSocket_Push_Helper::log('[Event Logger] Failed to update event status: ' . $wpdb->last_error, 'ERROR');
            return false;
        }
        
        FD_WebSocket_Push_Helper::log("[Event Logger] Event status updated: ID={$event_id}, Status={$status}");
        return true;
    }
    
    /**
     * 获取事件列表
     */
    public function get_events($limit = 50, $offset = 0, $event_type = null, $status = null) {
        global $wpdb;
        
        $where_conditions = array();
        $where_values = array();
        
        if ($event_type) {
            $where_conditions[] = 'event_type = %s';
            $where_values[] = $event_type;
        }
        
        if ($status) {
            $where_conditions[] = 'status = %s';
            $where_values[] = $status;
        }
        
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        $sql = "SELECT * FROM {$this->table_name} {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $where_values[] = $limit;
        $where_values[] = $offset;
        
        $prepared_sql = $wpdb->prepare($sql, $where_values);
        $results = $wpdb->get_results($prepared_sql);
        
        return $results;
    }
    
    /**
     * 获取事件统计
     */
    public function get_event_stats($hours = 24) {
        global $wpdb;
        
        $since = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        
        $sql = "SELECT 
                    event_type,
                    status,
                    COUNT(*) as count
                FROM {$this->table_name} 
                WHERE created_at >= %s 
                GROUP BY event_type, status 
                ORDER BY event_type, status";
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $since));
        
        $stats = array();
        foreach ($results as $row) {
            if (!isset($stats[$row->event_type])) {
                $stats[$row->event_type] = array();
            }
            $stats[$row->event_type][$row->status] = $row->count;
        }
        
        return $stats;
    }
    
    /**
     * 清理旧事件
     */
    public function cleanup_old_events($days = 7) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE created_at < %s",
            $cutoff_date
        ));
        
        if ($result !== false) {
            FD_WebSocket_Push_Helper::log("[Event Logger] Cleaned up {$result} old events older than {$days} days");
        }
        
        return $result;
    }
    
    /**
     * 获取最近的事件
     */
    public function get_recent_events($limit = 10) {
        global $wpdb;
        
        $sql = "SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d";
        $results = $wpdb->get_results($wpdb->prepare($sql, $limit));
        
        return $results;
    }
    
    /**
     * 获取事件类型列表
     */
    public function get_event_types() {
        global $wpdb;
        
        $sql = "SELECT DISTINCT event_type FROM {$this->table_name} ORDER BY event_type";
        $results = $wpdb->get_col($sql);
        
        return $results;
    }
    
    /**
     * 获取单个事件详情
     */
    public function get_event($event_id) {
        global $wpdb;
        
        $sql = "SELECT * FROM {$this->table_name} WHERE id = %d";
        $result = $wpdb->get_row($wpdb->prepare($sql, $event_id));
        
        return $result;
    }
    
    /**
     * 删除事件
     */
    public function delete_event($event_id) {
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->table_name,
            array('id' => $event_id),
            array('%d')
        );
        
        return $result !== false;
    }
    
    /**
     * 获取表名
     */
    public function get_table_name() {
        return $this->table_name;
    }
    
    /**
     * 检查表是否存在
     */
    public function table_exists() {
        global $wpdb;
        
        $table_name = $this->table_name;
        $result = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        
        return $result === $table_name;
    }
}
