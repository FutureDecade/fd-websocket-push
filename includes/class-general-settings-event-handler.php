<?php
/**
 * WordPress常规设置事件处理器
 * 监听WordPress常规设置的更新，并发送WebSocket事件通知前端刷新
 */
class FD_WebSocket_Push_General_Settings_Event_Handler {

    private $websocket_pusher;
    private $cache_invalidator;

    public function __construct($websocket_pusher, $cache_invalidator) {
        $this->websocket_pusher = $websocket_pusher;
        $this->cache_invalidator = $cache_invalidator;
        
        $this->register_hooks();
        
        error_log('[GeneralSettingsHandler] 常规设置事件处理器已初始化');
    }

    /**
     * 注册WordPress钩子
     */
    private function register_hooks() {
        // 监听常规设置更新 - WordPress设置页面的各项设置
        add_action('update_option_blogname', array($this, 'handle_general_setting_update'), 10, 2); // 站点标题
        add_action('update_option_blogdescription', array($this, 'handle_general_setting_update'), 10, 2); // 站点描述
        add_action('update_option_siteurl', array($this, 'handle_general_setting_update'), 10, 2); // WordPress地址
        add_action('update_option_home', array($this, 'handle_general_setting_update'), 10, 2); // 站点地址
        add_action('update_option_admin_email', array($this, 'handle_general_setting_update'), 10, 2); // 管理员邮箱
        add_action('update_option_WPLANG', array($this, 'handle_general_setting_update'), 10, 2); // 站点语言
        add_action('update_option_timezone_string', array($this, 'handle_general_setting_update'), 10, 2); // 时区
        add_action('update_option_date_format', array($this, 'handle_general_setting_update'), 10, 2); // 日期格式
        add_action('update_option_time_format', array($this, 'handle_general_setting_update'), 10, 2); // 时间格式
        add_action('update_option_start_of_week', array($this, 'handle_general_setting_update'), 10, 2); // 一周开始于
    }

    /**
     * 处理常规设置更新事件
     */
    public function handle_general_setting_update($old_value, $new_value) {
        // 获取当前钩子名称
        $current_hook = current_action();
        $setting_name = $this->get_setting_name_from_hook($current_hook);
        
        // 只有值真正改变时才处理
        if ($old_value !== $new_value) {
            error_log(sprintf(
                '[GeneralSettingsHandler] 常规设置 "%s" 已更新: "%s" -> "%s"',
                $setting_name,
                $this->format_value($old_value),
                $this->format_value($new_value)
            ));

            try {
                // 发送WebSocket事件到前端
                $this->send_general_settings_updated_event($setting_name, $old_value, $new_value);
                
                // 使缓存失效
                $this->invalidate_general_settings_cache();
                
                error_log('[GeneralSettingsHandler] ✅ 常规设置更新事件处理完成');

            } catch (Exception $e) {
                error_log('[GeneralSettingsHandler] ❌ 处理常规设置更新时出错: ' . $e->getMessage());
            }
        } else {
            error_log(sprintf(
                '[GeneralSettingsHandler] 常规设置 "%s" 值未改变，跳过处理',
                $setting_name
            ));
        }
    }

    /**
     * 从钩子名称获取设置名称
     */
    private function get_setting_name_from_hook($hook_name) {
        $mapping = array(
            'update_option_blogname' => '站点标题',
            'update_option_blogdescription' => '站点描述',
            'update_option_siteurl' => 'WordPress地址',
            'update_option_home' => '站点地址',
            'update_option_admin_email' => '管理员邮箱',
            'update_option_WPLANG' => '站点语言',
            'update_option_timezone_string' => '时区',
            'update_option_date_format' => '日期格式',
            'update_option_time_format' => '时间格式',
            'update_option_start_of_week' => '一周开始于',
        );
        
        return isset($mapping[$hook_name]) ? $mapping[$hook_name] : str_replace('update_option_', '', $hook_name);
    }

    /**
     * 发送常规设置更新的WebSocket事件
     */
    private function send_general_settings_updated_event($setting_name, $old_value, $new_value) {
        // 调用WebSocket推送器的专门方法
        $this->websocket_pusher->send_general_settings_updated_event($setting_name, $old_value, $new_value);
        
        error_log('[GeneralSettingsHandler] 📡 已发送常规设置更新WebSocket事件');
    }


    /**
     * 使常规设置相关缓存失效
     */
    private function invalidate_general_settings_cache() {
        try {
            // 使Next.js ISR缓存失效
            $this->cache_invalidator->revalidate_tag('general-settings');
            
            error_log('[GeneralSettingsHandler] 🔄 已触发常规设置缓存失效');
        } catch (Exception $e) {
            error_log('[GeneralSettingsHandler] ❌ 缓存失效失败: ' . $e->getMessage());
        }
    }

    /**
     * 格式化值用于日志显示
     */
    private function format_value($value) {
        if (is_string($value)) {
            return strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        return strval($value);
    }
}