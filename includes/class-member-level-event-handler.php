<?php
/**
 * 会员等级事件处理器
 * 监听会员等级相关的WordPress事件，并通过WebSocket推送更新通知
 */

if (!defined('ABSPATH')) {
    exit;
}

class FD_Member_Level_Event_Handler {
    
    /**
     * WebSocket推送器实例
     */
    private $websocket_pusher;
    
    /**
     * 构造函数
     */
    public function __construct($websocket_pusher) {
        $this->websocket_pusher = $websocket_pusher;
        $this->init_hooks();
    }
    
    /**
     * 初始化WordPress钩子
     */
    private function init_hooks() {
        // 监听会员等级选项更新
        add_action('update_option_fd_member_levels', array($this, 'handle_member_levels_updated'), 10, 3);
        
        // 监听默认会员等级更新
        add_action('update_option_fd_member_default_level', array($this, 'handle_default_level_updated'), 10, 3);
        
        // 监听用户会员等级变更
        add_action('update_user_meta', array($this, 'handle_user_member_level_updated'), 10, 4);
        add_action('delete_user_meta', array($this, 'handle_user_member_level_deleted'), 10, 4);
        
        // 监听会员等级过期时间变更
        add_action('update_user_meta', array($this, 'handle_user_member_expiration_updated'), 10, 4);
        
        // 监听会员升级相关的支付完成事件
        add_action('fd_payment_order_completed', array($this, 'handle_member_upgrade_payment_completed'), 10, 2);
        
        FD_WebSocket_Push_Helper::log('[Member Level Event Handler] Hooks initialized');
    }
    
    /**
     * 处理会员等级配置更新
     */
    public function handle_member_levels_updated($old_value, $new_value, $option_name) {
        FD_WebSocket_Push_Helper::log('[Member Level Event Handler] Member levels updated');
        
        try {
            // 发送会员等级更新事件
            $this->websocket_pusher->send_member_levels_updated_event();
            
            // 触发Next.js缓存失效
            $this->invalidate_nextjs_cache();
            
            FD_WebSocket_Push_Helper::log('[Member Level Event Handler] Member levels update event sent successfully');
            
        } catch (Exception $e) {
            FD_WebSocket_Push_Helper::log('[Member Level Event Handler] Error sending member levels update event: ' . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 处理默认会员等级更新
     */
    public function handle_default_level_updated($old_value, $new_value, $option_name) {
        FD_WebSocket_Push_Helper::log('[Member Level Event Handler] Default member level updated');
        
        try {
            // 发送会员等级更新事件
            $this->websocket_pusher->send_member_levels_updated_event();
            
            // 触发Next.js缓存失效
            $this->invalidate_nextjs_cache();
            
            FD_WebSocket_Push_Helper::log('[Member Level Event Handler] Default level update event sent successfully');
            
        } catch (Exception $e) {
            FD_WebSocket_Push_Helper::log('[Member Level Event Handler] Error sending default level update event: ' . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 处理用户会员等级更新
     */
    public function handle_user_member_level_updated($meta_id, $user_id, $meta_key, $meta_value) {
        // 只处理会员等级相关的meta
        if ($meta_key !== 'fd_member_level') {
            return;
        }
        
        FD_WebSocket_Push_Helper::log("[Member Level Event Handler] User {$user_id} member level updated to: {$meta_value}");
        
        try {
            // 发送用户会员等级更新事件
            $this->websocket_pusher->send_user_member_level_updated_event($user_id, $meta_value);
            
            FD_WebSocket_Push_Helper::log("[Member Level Event Handler] User member level update event sent successfully for user {$user_id}");
            
        } catch (Exception $e) {
            FD_WebSocket_Push_Helper::log("[Member Level Event Handler] Error sending user member level update event: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 处理用户会员等级删除
     */
    public function handle_user_member_level_deleted($meta_ids, $user_id, $meta_key, $meta_value) {
        // 只处理会员等级相关的meta
        if ($meta_key !== 'fd_member_level') {
            return;
        }
        
        FD_WebSocket_Push_Helper::log("[Member Level Event Handler] User {$user_id} member level deleted");
        
        try {
            // 发送用户会员等级删除事件
            $this->websocket_pusher->send_user_member_level_updated_event($user_id, null);
            
            FD_WebSocket_Push_Helper::log("[Member Level Event Handler] User member level delete event sent successfully for user {$user_id}");
            
        } catch (Exception $e) {
            FD_WebSocket_Push_Helper::log("[Member Level Event Handler] Error sending user member level delete event: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 处理用户会员等级过期时间更新
     */
    public function handle_user_member_expiration_updated($meta_id, $user_id, $meta_key, $meta_value) {
        // 只处理会员等级过期时间相关的meta
        if ($meta_key !== 'fd_member_level_expire') {
            return;
        }
        
        FD_WebSocket_Push_Helper::log("[Member Level Event Handler] User {$user_id} member level expiration updated to: {$meta_value}");
        
        try {
            // 发送用户会员等级过期时间更新事件
            $this->websocket_pusher->send_user_member_expiration_updated_event($user_id, $meta_value);
            
            FD_WebSocket_Push_Helper::log("[Member Level Event Handler] User member expiration update event sent successfully for user {$user_id}");
            
        } catch (Exception $e) {
            FD_WebSocket_Push_Helper::log("[Member Level Event Handler] Error sending user member expiration update event: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 处理会员升级支付完成事件
     */
    public function handle_member_upgrade_payment_completed($order_id, $order_data) {
        // 检查是否是会员等级相关的订单
        if (!isset($order_data['product_type']) || $order_data['product_type'] !== 'member_level') {
            return;
        }
        
        $user_id = $order_data['user_id'] ?? null;
        $level_id = $order_data['product_id'] ?? null;
        
        if (!$user_id || !$level_id) {
            return;
        }
        
        FD_WebSocket_Push_Helper::log("[Member Level Event Handler] Member upgrade payment completed for user {$user_id}, level {$level_id}");
        
        try {
            // 发送会员升级完成事件
            $this->websocket_pusher->send_member_upgrade_completed_event($user_id, $level_id, $order_id);
            
            FD_WebSocket_Push_Helper::log("[Member Level Event Handler] Member upgrade completed event sent successfully");
            
        } catch (Exception $e) {
            FD_WebSocket_Push_Helper::log("[Member Level Event Handler] Error sending member upgrade completed event: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 触发Next.js缓存失效
     */
    private function invalidate_nextjs_cache() {
        try {
            $nextjs_revalidate_url = get_option('fd_websocket_nextjs_revalidate_url', '');
            
            if (empty($nextjs_revalidate_url)) {
                FD_WebSocket_Push_Helper::log('[Member Level Event Handler] Next.js revalidate URL not configured');
                return;
            }
            
            // 构建重新验证URL，使用member-levels标签
            $revalidate_url = rtrim($nextjs_revalidate_url, '/') . '/api/revalidate';
            
            $response = wp_remote_post($revalidate_url, array(
                'timeout' => 10,
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode(array(
                    'tag' => 'member-levels',
                    'secret' => get_option('fd_websocket_revalidate_secret', '')
                ))
            ));
            
            if (is_wp_error($response)) {
                FD_WebSocket_Push_Helper::log('[Member Level Event Handler] Failed to invalidate Next.js cache: ' . $response->get_error_message(), 'ERROR');
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                if ($response_code === 200) {
                    FD_WebSocket_Push_Helper::log('[Member Level Event Handler] Next.js cache invalidated successfully');
                } else {
                    FD_WebSocket_Push_Helper::log('[Member Level Event Handler] Next.js cache invalidation failed with code: ' . $response_code, 'ERROR');
                }
            }
            
        } catch (Exception $e) {
            FD_WebSocket_Push_Helper::log('[Member Level Event Handler] Error invalidating Next.js cache: ' . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 获取会员等级信息用于事件数据
     */
    private function get_member_level_info($level_id) {
        if (!$level_id) {
            return null;
        }
        
        // 如果存在fd_member_get_member_level函数，使用它
        if (function_exists('fd_member_get_member_level')) {
            return fd_member_get_member_level($level_id);
        }
        
        // 否则直接从选项中获取
        $levels = get_option('fd_member_levels', array());
        foreach ($levels as $level) {
            if (isset($level['id']) && $level['id'] == $level_id) {
                return $level;
            }
        }
        
        return null;
    }
    
    /**
     * 获取用户信息用于事件数据
     */
    private function get_user_info($user_id) {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return null;
        }
        
        return array(
            'id' => $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
        );
    }
    
    /**
     * 检查事件处理器是否正常工作
     */
    public function health_check() {
        return array(
            'status' => 'ok',
            'handler' => 'member_level',
            'hooks_registered' => array(
                'update_option_fd_member_levels',
                'update_option_fd_member_default_level',
                'update_user_meta',
                'delete_user_meta',
                'fd_payment_order_completed'
            ),
            'websocket_pusher' => $this->websocket_pusher ? 'available' : 'not_available'
        );
    }
}
