/**
 * WebSocket推送事件管理页面JavaScript
 */

jQuery(document).ready(function($) {
    
    // 删除事件
    $('.fd-delete-event').on('click', function() {
        if (!confirm(fdWebSocketAdmin.confirmDelete)) {
            return;
        }
        
        const eventId = $(this).data('id');
        const $button = $(this);
        const $row = $button.closest('tr');
        
        $button.prop('disabled', true).text('删除中...');
        
        $.ajax({
            url: fdWebSocketAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'fd_websocket_delete_event',
                event_id: eventId,
                nonce: fdWebSocketAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                    });
                    showNotice('事件已删除', 'success');
                } else {
                    showNotice('删除失败: ' + response.data, 'error');
                    $button.prop('disabled', false).text('删除');
                }
            },
            error: function() {
                showNotice('删除失败: 网络错误', 'error');
                $button.prop('disabled', false).text('删除');
            }
        });
    });
    
    // 查看事件详情
    $('.fd-view-event').on('click', function() {
        const eventId = $(this).data('id');
        
        // 显示加载状态
        $('#fd-event-details').html('<div class="fd-loading"></div> 加载中...');
        $('#fd-event-modal').show();
        
        // 获取事件详情
        $.ajax({
            url: fdWebSocketAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'fd_websocket_get_event_details',
                event_id: eventId,
                nonce: fdWebSocketAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayEventDetails(response.data);
                } else {
                    $('#fd-event-details').html('<p>加载失败: ' + response.data + '</p>');
                }
            },
            error: function() {
                $('#fd-event-details').html('<p>加载失败: 网络错误</p>');
            }
        });
    });
    
    // 关闭模态框
    $('.fd-modal-close, .fd-modal').on('click', function(e) {
        if (e.target === this) {
            $('#fd-event-modal').hide();
        }
    });
    
    // 阻止模态框内容点击时关闭
    $('.fd-modal-content').on('click', function(e) {
        e.stopPropagation();
    });
    
    // 发送测试事件
    $('#fd-test-event').on('click', function() {
        const $button = $(this);
        
        $button.prop('disabled', true).text('发送中...');
        
        $.ajax({
            url: fdWebSocketAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'fd_websocket_test_event',
                nonce: fdWebSocketAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('测试事件已发送', 'success');
                } else {
                    showNotice('发送失败: ' + response.data, 'error');
                }
                $button.prop('disabled', false).text('发送测试事件');
            },
            error: function() {
                showNotice('发送失败: 网络错误', 'error');
                $button.prop('disabled', false).text('发送测试事件');
            }
        });
    });
    
    // 显示通知
    function showNotice(message, type) {
        const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        const $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
        
        $('.wrap h1').after($notice);
        
        // 自动隐藏
        setTimeout(function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
        
        // 添加关闭按钮功能
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        });
    }
    
    // 显示事件详情
    function displayEventDetails(event) {
        let html = '';
        
        // 基本信息
        html += '<div class="fd-event-detail">';
        html += '<h4>基本信息</h4>';
        html += '<table class="fd-stats-table">';
        html += '<tr><td><strong>ID:</strong></td><td>' + event.id + '</td></tr>';
        html += '<tr><td><strong>Trace ID:</strong></td><td><code>' + (event.trace_id || '') + '</code></td></tr>';
        html += '<tr><td><strong>事件类型:</strong></td><td>' + event.event_type + '</td></tr>';
        html += '<tr><td><strong>目标房间:</strong></td><td>' + event.target_room + '</td></tr>';
        html += '<tr><td><strong>状态:</strong></td><td><span class="fd-status fd-status-' + event.status + '">' + event.status + '</span></td></tr>';
        html += '<tr><td><strong>耗时:</strong></td><td>' + (event.duration_ms === null || event.duration_ms === undefined ? '' : event.duration_ms + 'ms') + '</td></tr>';
        html += '<tr><td><strong>创建时间:</strong></td><td>' + event.created_at + '</td></tr>';
        html += '<tr><td><strong>更新时间:</strong></td><td>' + event.updated_at + '</td></tr>';
        html += '</table>';
        html += '</div>';
        
        // 事件数据
        if (event.event_data) {
            html += '<div class="fd-event-detail">';
            html += '<h4>事件数据</h4>';
            html += '<pre class="fd-json">' + formatJson(event.event_data) + '</pre>';
            html += '</div>';
        }
        
        // 响应数据
        if (event.response_data) {
            html += '<div class="fd-event-detail">';
            html += '<h4>响应数据</h4>';
            html += '<pre class="fd-json">' + formatJson(event.response_data) + '</pre>';
            html += '</div>';
        }
        
        // 错误信息
        if (event.error_message) {
            html += '<div class="fd-event-detail">';
            html += '<h4>错误信息</h4>';
            html += '<pre style="color: #dc3232;">' + event.error_message + '</pre>';
            html += '</div>';
        }
        
        $('#fd-event-details').html(html);
    }
    
    // 格式化JSON
    function formatJson(jsonString) {
        try {
            const obj = JSON.parse(jsonString);
            return JSON.stringify(obj, null, 2);
        } catch (e) {
            return jsonString;
        }
    }
    
    // 键盘快捷键
    $(document).on('keydown', function(e) {
        // ESC键关闭模态框
        if (e.keyCode === 27) {
            $('#fd-event-modal').hide();
        }
    });
    
    // 自动刷新功能（可选）
    let autoRefreshInterval;
    
    function startAutoRefresh() {
        autoRefreshInterval = setInterval(function() {
            // 只在没有模态框打开时刷新
            if (!$('#fd-event-modal').is(':visible')) {
                location.reload();
            }
        }, 30000); // 30秒刷新一次
    }
    
    function stopAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }
    }
    
    // 页面可见性变化时控制自动刷新
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopAutoRefresh();
        } else {
            startAutoRefresh();
        }
    });
    
    // 启动自动刷新
    startAutoRefresh();
    
    // 页面卸载时停止自动刷新
    $(window).on('beforeunload', function() {
        stopAutoRefresh();
    });
});
