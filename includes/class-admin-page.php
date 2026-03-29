<?php
/**
 * WebSocket推送事件管理后台页面
 */

if (!defined('ABSPATH')) {
    exit;
}

class FD_WebSocket_Push_Admin_Page {
    
    /**
     * Event logger instance
     */
    private $event_logger;
    
    /**
     * 构造函数
     */
    public function __construct() {
        $this->event_logger = new FD_WebSocket_Push_Event_Logger();
        
        // 注册管理页面
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // 注册AJAX处理器
        add_action('wp_ajax_fd_websocket_delete_event', array($this, 'ajax_delete_event'));
        add_action('wp_ajax_fd_websocket_cleanup_events', array($this, 'ajax_cleanup_events'));
        add_action('wp_ajax_fd_websocket_test_event', array($this, 'ajax_test_event'));
        add_action('wp_ajax_fd_websocket_get_event_details', array($this, 'ajax_get_event_details'));
        
        // 注册样式和脚本
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    /**
     * 添加管理菜单
     */
    public function add_admin_menu() {
        add_management_page(
            'WebSocket监测',
            'WebSocket监测',
            'manage_options',
            'fd-websocket-events',
            array($this, 'admin_page_content')
        );
    }
    
    /**
     * 加载管理页面资源
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'tools_page_fd-websocket-events') {
            return;
        }
        
        wp_enqueue_style('fd-websocket-admin', FD_WEBSOCKET_PUSH_PLUGIN_URL . 'assets/admin.css', array(), FD_WEBSOCKET_PUSH_VERSION);
        wp_enqueue_script('fd-websocket-admin', FD_WEBSOCKET_PUSH_PLUGIN_URL . 'assets/admin.js', array('jquery'), FD_WEBSOCKET_PUSH_VERSION, true);
        
        wp_localize_script('fd-websocket-admin', 'fdWebSocketAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fd_websocket_admin'),
            'confirmDelete' => '确定要删除这个事件吗？',
            'confirmCleanup' => '确定要清理旧事件吗？这将删除7天前的所有事件。'
        ));
    }
    
    /**
     * 管理页面内容
     */
    public function admin_page_content() {
        // 检查FD Admin UI是否可用
        $use_fd_ui = class_exists('FD_Admin_UI');
        
        // 处理表单提交
        if (isset($_POST['action']) && $_POST['action'] === 'cleanup_events') {
            check_admin_referer('fd_websocket_cleanup');
            $days = intval($_POST['cleanup_days']);
            $deleted = $this->event_logger->cleanup_old_events($days);
            
            if ($use_fd_ui) {
                echo FD_Admin_UI::render_notice('已清理 ' . $deleted . ' 个旧事件。', 'success');
            } else {
                echo '<div class="notice notice-success"><p>已清理 ' . $deleted . ' 个旧事件。</p></div>';
            }
        }
        
        // 获取筛选参数
        $event_type = isset($_GET['event_type']) ? sanitize_text_field($_GET['event_type']) : '';
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // 获取事件数据
        $events = $this->event_logger->get_events($per_page, $offset, $event_type, $status);
        $event_types = $this->event_logger->get_event_types();
        $stats = $this->event_logger->get_event_stats(24);
        
        // 计算统计总数
        $total_sent = 0;
        $total_failed = 0;
        $total_pending = 0;
        foreach ($stats as $type_stats) {
            $total_sent += intval($type_stats['sent'] ?? 0);
            $total_failed += intval($type_stats['failed'] ?? 0);
            $total_pending += intval($type_stats['pending'] ?? 0);
        }
        
        if ($use_fd_ui) {
            // 使用FD Admin UI风格
            ?>
            <div class="wrap fd-admin-page">
                <?php 
                echo FD_Admin_UI::render_page_header(
                    'WebSocket监测',
                    'WebSocket推送事件实时监测与管理'
                );
                ?>
                
                <!-- 紧凑的统计和操作栏 -->
                <div style="display: flex; gap: 20px; margin-bottom: 20px; align-items: center; background: #fff; padding: 15px 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <!-- 统计数据 -->
                    <div style="display: flex; gap: 30px; flex: 1;">
                        <div>
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">24小时已发送</div>
                            <div style="font-size: 24px; font-weight: 600; color: #10b981;"><?php echo $total_sent; ?></div>
                        </div>
                        <div>
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">失败</div>
                            <div style="font-size: 24px; font-weight: 600; color: #ef4444;"><?php echo $total_failed; ?></div>
                        </div>
                        <div>
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">待处理</div>
                            <div style="font-size: 24px; font-weight: 600; color: #f59e0b;"><?php echo $total_pending; ?></div>
                        </div>
                    </div>
                    
                    <!-- 快速操作 -->
                    <div style="display: flex; gap: 10px; align-items: center; padding-left: 30px; border-left: 1px solid #e5e7eb;">
                        <button type="button" class="fd-btn fd-btn-secondary fd-btn-sm" id="fd-test-event">测试事件</button>
                        <form method="post" style="display: flex; gap: 8px; align-items: center; margin: 0;">
                            <?php wp_nonce_field('fd_websocket_cleanup'); ?>
                            <input type="hidden" name="action" value="cleanup_events">
                            <input type="number" name="cleanup_days" id="cleanup_days" value="7" min="1" max="30" style="width: 50px; padding: 6px; font-size: 13px;">
                            <span style="font-size: 13px; color: #666;">天前</span>
                            <button type="submit" class="fd-btn fd-btn-secondary fd-btn-sm" onclick="return confirm('确定要清理旧事件吗？')">清理</button>
                        </form>
                    </div>
                    
                    <!-- 筛选器 -->
                    <div style="padding-left: 30px; border-left: 1px solid #e5e7eb;">
                        <form method="get" style="display: flex; gap: 8px; align-items: center; margin: 0;">
                            <input type="hidden" name="page" value="fd-websocket-events">
                            
                            <select name="event_type" style="padding: 6px 10px; font-size: 13px;">
                                <option value="">所有类型</option>
                                <?php foreach ($event_types as $type): ?>
                                    <option value="<?php echo esc_attr($type); ?>" <?php selected($event_type, $type); ?>>
                                        <?php echo esc_html($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select name="status" style="padding: 6px 10px; font-size: 13px;">
                                <option value="">所有状态</option>
                                <option value="pending" <?php selected($status, 'pending'); ?>>待处理</option>
                                <option value="sent" <?php selected($status, 'sent'); ?>>已发送</option>
                                <option value="failed" <?php selected($status, 'failed'); ?>>失败</option>
                            </select>
                            
                            <button type="submit" class="fd-btn fd-btn-primary fd-btn-sm">筛选</button>
                            <?php if ($event_type || $status): ?>
                                <a href="<?php echo admin_url('tools.php?page=fd-websocket-events'); ?>" class="fd-btn fd-btn-secondary fd-btn-sm">重置</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <!-- 事件列表 -->
                <?php echo FD_Admin_UI::render_card_start('事件列表'); ?>
                    <?php if (empty($events)): ?>
                        <p style="color: #666; padding: 20px 0;">没有找到事件记录。</p>
                    <?php else: ?>
                        <div class="fd-table-wrapper">
                            <table class="fd-table">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">ID</th>
                                        <th style="width: 200px;">事件类型</th>
                                        <th style="width: 150px;">目标房间</th>
                                        <th style="width: 80px; text-align: center;">状态</th>
                                        <th style="width: 140px;">创建时间</th>
                                        <th style="width: 140px;">更新时间</th>
                                        <th style="width: 120px; text-align: center;">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($events as $event): ?>
                                    <tr>
                                        <td><?php echo esc_html($event->id); ?></td>
                                        <td><code style="font-size: 12px;"><?php echo esc_html($event->event_type); ?></code></td>
                                        <td><code style="font-size: 12px;"><?php echo esc_html($event->target_room); ?></code></td>
                                        <td style="text-align: center;">
                                            <?php
                                            $badge_type = 'default';
                                            if ($event->status === 'sent') $badge_type = 'success';
                                            elseif ($event->status === 'failed') $badge_type = 'error';
                                            elseif ($event->status === 'pending') $badge_type = 'warning';
                                            echo FD_Admin_UI::render_badge($event->status, $badge_type);
                                            ?>
                                        </td>
                                        <td style="font-size: 13px;"><?php echo esc_html($event->created_at); ?></td>
                                        <td style="font-size: 13px;"><?php echo esc_html($event->updated_at); ?></td>
                                        <td style="text-align: center;">
                                            <button type="button" class="fd-btn fd-btn-secondary fd-btn-sm fd-view-event" data-id="<?php echo esc_attr($event->id); ?>">
                                                查看
                                            </button>
                                            <button type="button" class="fd-btn fd-btn-ghost fd-btn-sm fd-delete-event" data-id="<?php echo esc_attr($event->id); ?>" style="margin-left: 4px; color: #ef4444;">
                                                删除
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    
                    <!-- 分页 -->
                    <?php
                    $total_events = count($this->event_logger->get_events(999999, 0, $event_type, $status));
                    $total_pages = ceil($total_events / $per_page);
                    
                    if ($total_pages > 1):
                        $page_links = paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '‹',
                            'next_text' => '›',
                            'total' => $total_pages,
                            'current' => $page,
                            'type' => 'array',
                            'mid_size' => 2
                        ));
                        
                        if ($page_links):
                            echo '<div style="display: flex; justify-content: center; align-items: center; gap: 4px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #e5e7eb;">';
                            foreach ($page_links as $link) {
                                $link = str_replace('page-numbers', 'fd-page-num', $link);
                                echo $link;
                            }
                            echo '</div>';
                            
                            // 添加分页样式 - 参照图片中的简洁设计
                            echo '<style>
                                .fd-page-num {
                                    display: inline-flex;
                                    align-items: center;
                                    justify-content: center;
                                    min-width: 28px;
                                    height: 28px;
                                    padding: 0 6px;
                                    font-size: 13px;
                                    font-weight: 400;
                                    color: #6b7280;
                                    background: transparent;
                                    border: none;
                                    border-radius: 4px;
                                    text-decoration: none;
                                    transition: all 0.15s;
                                }
                                .fd-page-num:hover {
                                    background: #f3f4f6;
                                    color: #111827;
                                }
                                .fd-page-num.current {
                                    background: #f3f4f6;
                                    color: #111827;
                                    font-weight: 500;
                                }
                                .fd-page-num.dots {
                                    background: transparent;
                                    cursor: default;
                                    color: #9ca3af;
                                }
                                .fd-page-num.dots:hover {
                                    background: transparent;
                                    color: #9ca3af;
                                }
                            </style>';
                        endif;
                    endif;
                    ?>
                <?php echo FD_Admin_UI::render_card_end(); ?>
            </div>
            
            <!-- 事件详情模态框 -->
            <div id="fd-event-modal" class="fd-modal" style="display: none;">
                <div class="fd-modal-content">
                    <div class="fd-modal-header">
                        <h2>事件详情</h2>
                        <span class="fd-modal-close">&times;</span>
                    </div>
                    <div class="fd-modal-body">
                        <div id="fd-event-details"></div>
                    </div>
                </div>
            </div>
            <?php
        } else {
            // 降级到原生WordPress UI
            ?>
            <div class="wrap">
                <h1>WebSocket监测</h1>
                
                <!-- 统计信息 -->
                <div class="fd-stats-grid">
                    <div class="fd-stat-card">
                        <h3>24小时统计</h3>
                        <?php if (empty($stats)): ?>
                            <p>暂无事件数据</p>
                        <?php else: ?>
                            <table class="fd-stats-table">
                                <thead>
                                    <tr>
                                        <th>事件类型</th>
                                        <th>成功</th>
                                        <th>失败</th>
                                        <th>待处理</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats as $type => $type_stats): ?>
                                    <tr>
                                        <td><?php echo esc_html($type); ?></td>
                                        <td class="fd-stat-success"><?php echo intval($type_stats['sent'] ?? 0); ?></td>
                                        <td class="fd-stat-error"><?php echo intval($type_stats['failed'] ?? 0); ?></td>
                                        <td class="fd-stat-pending"><?php echo intval($type_stats['pending'] ?? 0); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <div class="fd-stat-card">
                        <h3>快速操作</h3>
                        <p>
                            <button type="button" class="button" id="fd-test-event">发送测试事件</button>
                        </p>
                        <form method="post" style="margin-top: 15px;">
                            <?php wp_nonce_field('fd_websocket_cleanup'); ?>
                            <input type="hidden" name="action" value="cleanup_events">
                            <label for="cleanup_days">清理天数前的事件：</label>
                            <input type="number" name="cleanup_days" id="cleanup_days" value="7" min="1" max="30" style="width: 60px;">
                            <input type="submit" class="button" value="清理旧事件" onclick="return confirm('确定要清理旧事件吗？')">
                        </form>
                    </div>
                </div>
                
                <!-- 筛选器 -->
                <div class="fd-filters">
                    <form method="get">
                        <input type="hidden" name="page" value="fd-websocket-events">
                        
                        <select name="event_type">
                            <option value="">所有事件类型</option>
                            <?php foreach ($event_types as $type): ?>
                                <option value="<?php echo esc_attr($type); ?>" <?php selected($event_type, $type); ?>>
                                    <?php echo esc_html($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="status">
                            <option value="">所有状态</option>
                            <option value="pending" <?php selected($status, 'pending'); ?>>待处理</option>
                            <option value="sent" <?php selected($status, 'sent'); ?>>已发送</option>
                            <option value="failed" <?php selected($status, 'failed'); ?>>失败</option>
                        </select>
                        
                        <input type="submit" class="button" value="筛选">
                        <a href="<?php echo admin_url('tools.php?page=fd-websocket-events'); ?>" class="button">重置</a>
                    </form>
                </div>
                
                <!-- 事件列表 -->
                <div class="fd-events-table">
                    <?php if (empty($events)): ?>
                        <p>没有找到事件记录。</p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>事件类型</th>
                                    <th>目标房间</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th>更新时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $event): ?>
                                <tr>
                                    <td><?php echo esc_html($event->id); ?></td>
                                    <td><?php echo esc_html($event->event_type); ?></td>
                                    <td><?php echo esc_html($event->target_room); ?></td>
                                    <td>
                                        <span class="fd-status fd-status-<?php echo esc_attr($event->status); ?>">
                                            <?php echo esc_html($event->status); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($event->created_at); ?></td>
                                    <td><?php echo esc_html($event->updated_at); ?></td>
                                    <td>
                                        <button type="button" class="button-small fd-view-event" data-id="<?php echo esc_attr($event->id); ?>">
                                            查看详情
                                        </button>
                                        <button type="button" class="button-small fd-delete-event" data-id="<?php echo esc_attr($event->id); ?>">
                                            删除
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                
                <!-- 分页 -->
                <?php
                $total_events = count($this->event_logger->get_events(999999, 0, $event_type, $status));
                $total_pages = ceil($total_events / $per_page);
                
                if ($total_pages > 1):
                    $page_links = paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total' => $total_pages,
                        'current' => $page
                    ));
                    
                    if ($page_links):
                        echo '<div class="tablenav"><div class="tablenav-pages">' . $page_links . '</div></div>';
                    endif;
                endif;
                ?>
            </div>
            
            <!-- 事件详情模态框 -->
            <div id="fd-event-modal" class="fd-modal" style="display: none;">
                <div class="fd-modal-content">
                    <div class="fd-modal-header">
                        <h2>事件详情</h2>
                        <span class="fd-modal-close">&times;</span>
                    </div>
                    <div class="fd-modal-body">
                        <div id="fd-event-details"></div>
                    </div>
                </div>
            </div>
            <?php
        }
    }
    
    /**
     * AJAX删除事件
     */
    public function ajax_delete_event() {
        check_ajax_referer('fd_websocket_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        
        $event_id = intval($_POST['event_id']);
        $result = $this->event_logger->delete_event($event_id);
        
        if ($result) {
            wp_send_json_success('事件已删除');
        } else {
            wp_send_json_error('删除失败');
        }
    }
    
    /**
     * AJAX清理事件
     */
    public function ajax_cleanup_events() {
        check_ajax_referer('fd_websocket_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        
        $days = intval($_POST['days']);
        $deleted = $this->event_logger->cleanup_old_events($days);
        
        wp_send_json_success("已清理 {$deleted} 个旧事件");
    }
    
    /**
     * AJAX测试事件
     */
    public function ajax_test_event() {
        check_ajax_referer('fd_websocket_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        
        $pusher = FD_WebSocket_Push_WebSocket_Pusher::get_instance();
        $result = $pusher->send_event('test:admin-event', 'public', array(
            'message' => '这是一个来自后台的测试事件',
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id()
        ));
        
        if ($result === true) {
            wp_send_json_success('测试事件已发送');
        } else {
            wp_send_json_error('测试事件发送失败');
        }
    }

    /**
     * AJAX获取事件详情
     */
    public function ajax_get_event_details() {
        check_ajax_referer('fd_websocket_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }

        $event_id = intval($_POST['event_id']);
        $event = $this->event_logger->get_event($event_id);

        if ($event) {
            wp_send_json_success($event);
        } else {
            wp_send_json_error('事件不存在');
        }
    }
}
