<?php
/**
 * 当前用户状态 WebSocket 事件处理器
 *
 * 监听用户个人状态变更事件，如：
 * - 用户资料更新（头像、昵称、个人信息等）
 * - 用户会员等级变更（升级、降级）
 * - 用户权限变更
 * - 用户认证状态变更（邮箱验证、手机绑定等）
 * - 用户订阅状态变更
 * 
 * @package FD_WebSocket_Push
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class FD_Current_User_Event_Handler {
    
    private $websocket_pusher;
    
    public function __construct($websocket_pusher) {
        $this->websocket_pusher = $websocket_pusher;
        $this->init_hooks();
    }
    
    /**
     * 初始化 WordPress 钩子
     */
    private function init_hooks() {
        // 用户资料更新事件
        add_action('profile_update', array($this, 'handle_profile_update'), 10, 2);
        add_action('user_register', array($this, 'handle_user_register'), 10, 1);
        
        // 用户自定义字段更新
        add_action('updated_user_meta', array($this, 'handle_user_meta_update'), 10, 4);
        add_action('added_user_meta', array($this, 'handle_user_meta_update'), 10, 4);
        
        // 用户角色和权限变更
        add_action('add_user_role', array($this, 'handle_user_role_change'), 10, 2);
        add_action('remove_user_role', array($this, 'handle_user_role_change'), 10, 2);
        add_action('set_user_role', array($this, 'handle_user_role_change'), 10, 3);
        
        // 用户登录和登出
        add_action('wp_login', array($this, 'handle_user_login'), 10, 2);
        add_action('wp_logout', array($this, 'handle_user_logout'), 10, 1);
        
        // 密码重置
        add_action('password_reset', array($this, 'handle_password_reset'), 10, 2);
        
        // GraphQL 用户更新 mutations（如果使用 WPGraphQL）
        add_action('graphql_user_object_mutation_update_additional_data', array($this, 'handle_graphql_user_mutation'), 10, 5);
        
        // 会员等级变更（集成会员系统）
        add_action('user_membership_level_changed', array($this, 'handle_membership_level_change'), 10, 3);
        
        // 邮箱验证状态变更
        add_action('user_email_verified', array($this, 'handle_email_verification'), 10, 1);
        add_action('user_email_unverified', array($this, 'handle_email_verification'), 10, 1);
        
        // 手机号绑定状态变更
        add_action('user_phone_bound', array($this, 'handle_phone_binding'), 10, 2);
        add_action('user_phone_unbound', array($this, 'handle_phone_binding'), 10, 1);
        
        // 订阅状态变更
        add_action('user_subscription_created', array($this, 'handle_subscription_change'), 10, 2);
        add_action('user_subscription_updated', array($this, 'handle_subscription_change'), 10, 2);
        add_action('user_subscription_cancelled', array($this, 'handle_subscription_change'), 10, 2);
        add_action('user_subscription_expired', array($this, 'handle_subscription_change'), 10, 2);
    }
    
    /**
     * 处理用户资料更新
     * 
     * @param int $user_id 用户ID
     * @param WP_User $old_user_data 更新前的用户数据
     */
    public function handle_profile_update($user_id, $old_user_data) {
        error_log("[Current User Event] 用户资料更新 - 用户ID: {$user_id}");
        
        $user = get_user_by('id', $user_id);
        if (!$user) {
            error_log("[Current User Event] 无法获取用户数据 - 用户ID: {$user_id}");
            return;
        }
        
        // 检查具体哪些字段发生了变更
        $changes = array();
        
        if ($user->display_name !== $old_user_data->display_name) {
            $changes['display_name'] = array(
                'old' => $old_user_data->display_name,
                'new' => $user->display_name
            );
        }
        
        if ($user->user_email !== $old_user_data->user_email) {
            $changes['email'] = array(
                'old' => $old_user_data->user_email,
                'new' => $user->user_email
            );
        }
        
        if (empty($changes)) {
            error_log("[Current User Event] 用户基本信息无变更，跳过推送");
            return;
        }
        
        $this->push_user_status_update($user_id, 'profile_updated', array(
            'changes' => $changes,
            'user_data' => $this->get_current_user_data($user_id)
        ));
    }
    
    /**
     * 处理用户自定义字段更新
     * 
     * @param int $meta_id Meta ID
     * @param int $user_id 用户ID
     * @param string $meta_key Meta键
     * @param mixed $meta_value Meta值
     */
    public function handle_user_meta_update($meta_id, $user_id, $meta_key, $meta_value) {
        // 只处理重要的用户meta字段
        $important_meta_keys = array(
            'user_avatar',
            'user_bio',
            'user_website',
            'user_location',
            'user_birth_date',
            'user_gender',
            'user_phone',
            'user_member_level',
            'email_verified',
            'phone_verified',
            'user_preferences',
            'social_links'
        );
        
        if (!in_array($meta_key, $important_meta_keys)) {
            return;
        }
        
        error_log("[Current User Event] 用户Meta更新 - 用户ID: {$user_id}, 字段: {$meta_key}");
        
        $this->push_user_status_update($user_id, 'meta_updated', array(
            'meta_key' => $meta_key,
            'meta_value' => $meta_value,
            'user_data' => $this->get_current_user_data($user_id)
        ));
    }
    
    /**
     * 处理用户角色变更
     * 
     * @param int $user_id 用户ID
     * @param string $role 角色
     * @param string|null $old_roles 旧角色（仅在set_user_role时提供）
     */
    public function handle_user_role_change($user_id, $role, $old_roles = null) {
        error_log("[Current User Event] 用户角色变更 - 用户ID: {$user_id}, 角色: {$role}");
        
        $this->push_user_status_update($user_id, 'role_updated', array(
            'role' => $role,
            'old_roles' => $old_roles,
            'user_data' => $this->get_current_user_data($user_id)
        ));
    }
    
    /**
     * 处理用户登录
     * 
     * @param string $user_login 用户登录名
     * @param WP_User $user 用户对象
     */
    public function handle_user_login($user_login, $user) {
        error_log("[Current User Event] 用户登录 - 用户ID: {$user->ID}, 登录名: {$user_login}");
        
        // 更新最后登录时间
        update_user_meta($user->ID, 'last_login_time', current_time('mysql'));
        update_user_meta($user->ID, 'last_login_ip', $this->get_client_ip());
        
        $this->push_user_status_update($user->ID, 'logged_in', array(
            'login_time' => current_time('mysql'),
            'login_ip' => $this->get_client_ip(),
            'user_data' => $this->get_current_user_data($user->ID)
        ));
    }
    
    /**
     * 处理用户登出
     * 
     * @param int $user_id 用户ID
     */
    public function handle_user_logout($user_id) {
        error_log("[Current User Event] 用户登出 - 用户ID: {$user_id}");
        
        $this->push_user_status_update($user_id, 'logged_out', array(
            'logout_time' => current_time('mysql')
        ));
    }
    
    /**
     * 处理会员等级变更
     * 
     * @param int $user_id 用户ID
     * @param string $old_level 旧等级
     * @param string $new_level 新等级
     */
    public function handle_membership_level_change($user_id, $old_level, $new_level) {
        error_log("[Current User Event] 会员等级变更 - 用户ID: {$user_id}, 从 {$old_level} 变更为 {$new_level}");
        
        $this->push_user_status_update($user_id, 'membership_level_changed', array(
            'old_level' => $old_level,
            'new_level' => $new_level,
            'upgrade_time' => current_time('mysql'),
            'user_data' => $this->get_current_user_data($user_id)
        ));
    }
    
    /**
     * 处理邮箱验证状态变更
     * 
     * @param int $user_id 用户ID
     */
    public function handle_email_verification($user_id) {
        error_log("[Current User Event] 邮箱验证状态变更 - 用户ID: {$user_id}");
        
        $is_verified = get_user_meta($user_id, 'email_verified', true);
        
        $this->push_user_status_update($user_id, 'email_verification_changed', array(
            'verified' => (bool) $is_verified,
            'verification_time' => current_time('mysql'),
            'user_data' => $this->get_current_user_data($user_id)
        ));
    }
    
    /**
     * 处理手机绑定状态变更
     * 
     * @param int $user_id 用户ID
     * @param string|null $phone 手机号（绑定时提供）
     */
    public function handle_phone_binding($user_id, $phone = null) {
        error_log("[Current User Event] 手机绑定状态变更 - 用户ID: {$user_id}");
        
        $is_bound = !empty($phone);
        
        $this->push_user_status_update($user_id, 'phone_binding_changed', array(
            'bound' => $is_bound,
            'phone' => $phone ? substr($phone, 0, 3) . '****' . substr($phone, -4) : null,
            'binding_time' => current_time('mysql'),
            'user_data' => $this->get_current_user_data($user_id)
        ));
    }
    
    /**
     * 处理订阅状态变更
     * 
     * @param int $user_id 用户ID
     * @param array $subscription_data 订阅数据
     */
    public function handle_subscription_change($user_id, $subscription_data) {
        error_log("[Current User Event] 订阅状态变更 - 用户ID: {$user_id}");
        
        $this->push_user_status_update($user_id, 'subscription_changed', array(
            'subscription' => $subscription_data,
            'change_time' => current_time('mysql'),
            'user_data' => $this->get_current_user_data($user_id)
        ));
    }
    
    /**
     * 推送用户状态更新事件
     * 
     * @param int $user_id 用户ID
     * @param string $event_type 事件类型
     * @param array $event_data 事件数据
     */
    private function push_user_status_update($user_id, $event_type, $event_data) {
        if (!$this->websocket_pusher) {
            error_log("[Current User Event] WebSocket推送器未初始化");
            return;
        }
        
        // 构造事件数据 - 避免重复嵌套
        $push_data = array(
            'type' => $event_type,
            'data' => $event_data,
            'timestamp' => current_time('mysql'),
            'user_id' => $user_id
        );
        
        try {
            // 发送到用户私有频道 - 使用下划线格式与会员等级事件保持一致
            $target = "user_{$user_id}"; // 私有用户频道
            $this->websocket_pusher->send_event('current-user:updated', $target, $push_data);
            
            error_log("[Current User Event] 用户状态更新事件已推送 - 用户ID: {$user_id}, 事件: {$event_type}");
            
        } catch (Exception $e) {
            error_log("[Current User Event] 推送失败: " . $e->getMessage());
        }
    }
    
    /**
     * 获取当前用户完整数据
     * 
     * @param int $user_id 用户ID
     * @return array 用户数据
     */
    private function get_current_user_data($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return null;
        }
        
        // 构造与前端GetCurrentUser查询相匹配的数据结构
        return array(
            'id' => $user->ID,
            'name' => $user->display_name,
            'email' => $user->user_email,
            'avatar' => get_user_meta($user_id, 'user_avatar', true) ?: null,
            'slug' => $user->user_login,
            'memberLevel' => $this->get_user_member_level($user_id),
            'isAdmin' => user_can($user, 'manage_options'),
            'emailVerified' => (bool) get_user_meta($user_id, 'email_verified', true),
            'phoneVerified' => (bool) get_user_meta($user_id, 'phone_verified', true),
            'phone' => get_user_meta($user_id, 'user_phone', true) ?: null,
            'bio' => get_user_meta($user_id, 'user_bio', true) ?: null,
            'website' => $user->user_url ?: null,
            'location' => get_user_meta($user_id, 'user_location', true) ?: null,
            'birthDate' => get_user_meta($user_id, 'user_birth_date', true) ?: null,
            'gender' => get_user_meta($user_id, 'user_gender', true) ?: null,
            'socialLinks' => $this->get_user_social_links($user_id),
            'preferences' => $this->get_user_preferences($user_id),
            'subscription' => $this->get_user_subscription($user_id),
            'stats' => $this->get_user_stats($user_id),
            'createdAt' => $user->user_registered,
            'updatedAt' => current_time('mysql')
        );
    }
    
    /**
     * 获取用户会员等级信息
     */
    private function get_user_member_level($user_id) {
        $level_id = get_user_meta($user_id, 'user_member_level', true);
        if (!$level_id) {
            return null;
        }
        
        // 这里需要根据实际的会员等级系统调用相应的API
        // 示例实现
        return array(
            'id' => $level_id,
            'name' => get_user_meta($user_id, 'member_level_name', true) ?: 'Free',
            'price' => (int) get_user_meta($user_id, 'member_level_price', true) ?: 0,
            'features' => array(),
            'color' => get_user_meta($user_id, 'member_level_color', true) ?: '#666666'
        );
    }
    
    /**
     * 获取用户社交链接
     */
    private function get_user_social_links($user_id) {
        $social_links = get_user_meta($user_id, 'social_links', true);
        return is_array($social_links) ? $social_links : array();
    }
    
    /**
     * 获取用户偏好设置
     */
    private function get_user_preferences($user_id) {
        $preferences = get_user_meta($user_id, 'user_preferences', true);
        if (!is_array($preferences)) {
            $preferences = array();
        }
        
        return array_merge(array(
            'theme' => 'light',
            'language' => 'zh-CN',
            'timezone' => 'Asia/Shanghai',
            'emailNotifications' => true,
            'pushNotifications' => true
        ), $preferences);
    }
    
    /**
     * 获取用户订阅信息
     */
    private function get_user_subscription($user_id) {
        $subscription = get_user_meta($user_id, 'user_subscription', true);
        return is_array($subscription) ? $subscription : null;
    }
    
    /**
     * 获取用户统计信息
     */
    private function get_user_stats($user_id) {
        return array(
            'postsCount' => (int) count_user_posts($user_id),
            'followersCount' => (int) get_user_meta($user_id, 'followers_count', true) ?: 0,
            'followingCount' => (int) get_user_meta($user_id, 'following_count', true) ?: 0,
            'likesCount' => (int) get_user_meta($user_id, 'likes_count', true) ?: 0
        );
    }
    
    /**
     * 获取客户端IP地址
     */
    private function get_client_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        }
    }
    
    /**
     * 处理GraphQL用户变更
     * 
     * @param int $user_id 用户ID
     * @param array $input 输入数据
     * @param string $mutation_name Mutation名称
     * @param mixed $context GraphQL 上下文
     * @param mixed $info GraphQL ResolveInfo
     */
    public function handle_graphql_user_mutation($user_id, $input, $mutation_name, $context = null, $info = null) {
        $user_id = absint($user_id);
        if (!$user_id) {
            return;
        }

        error_log("[Current User Event] GraphQL用户Mutation - {$mutation_name}, 用户ID: {$user_id}");

        $this->push_user_status_update($user_id, 'graphql_updated', array(
            'mutation' => $mutation_name,
            'input' => $input,
            'user_data' => $this->get_current_user_data($user_id)
        ));
    }
}
