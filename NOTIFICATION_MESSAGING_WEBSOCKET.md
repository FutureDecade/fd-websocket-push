# 通知和私信WebSocket实时推送功能

## 功能概述

本功能为FD WebSocket Push插件添加了通知和私信的实时推送支持，实现了以下功能：

- ✅ 通知创建时的实时WebSocket推送
- ✅ 私信发送时的实时WebSocket推送  
- ✅ 前端实时事件监听和缓存更新
- ✅ 浏览器通知集成
- ✅ 通知权限管理

## 架构设计

```
WordPress后端 → GraphQL API → Next.js前端
     ↓              ↓              ↑
WebSocket推送 → Socket.io服务 → 实时更新
     ↓              ↓              ↑
数据库存储 ← 事件触发 ← 用户操作
```

## WebSocket事件类型

| 事件类型 | 目标房间 | 数据结构 | 触发时机 |
|---------|---------|---------|---------|
| `notification:created` | `user_{userId}` | `{notificationId, title, content, type, createdAt}` | 通知创建后 |
| `message:received` | `user_{recipientId}` | `{messageId, senderId, content, conversationId, sentAt}` | 私信发送后 |
| `conversation:updated` | `user_{userId}` | `{conversationId, unreadCount, updatedAt}` | 会话更新后 |

## 安装和配置

### 1. 后端配置

确保在 `wp-config.php` 中定义了以下常量：

```php
// WebSocket推送密钥
define('FD_WEBSOCKET_PUSH_SECRET', 'your-websocket-secret');

// WebSocket服务器URL（可选，默认为 http://localhost:8082）
define('FD_WEBSOCKET_URL', 'http://your-websocket-server:8082');
```

### 2. 前端配置

确保在 `.env.local` 中配置了WebSocket服务器URL：

```env
NEXT_PUBLIC_WEBSOCKET_URL=http://localhost:8082
```

### 3. WebSocket服务器

确保WebSocket服务器（fd-websocket）正在运行：

```bash
cd fd-websocket
npm start
```

## 使用方法

### 后端集成

#### 通知推送

通知推送会在调用 `fd_member_notification_create()` 函数时自动触发：

```php
// 创建通知，会自动触发WebSocket推送
$notification_id = fd_member_notification_create(
    $user_id,
    '通知标题',
    '通知内容',
    'system'
);
```

#### 私信推送

私信推送会在调用 `fd_member_send_pm()` 函数时自动触发：

```php
// 发送私信，会自动触发WebSocket推送
$message_data = [
    'sender_id' => $sender_id,
    'recipient_id' => $recipient_id,
    'subject' => '私信主题',
    'content' => '私信内容'
];

$message_id = fd_member_send_pm($message_data);
```

### 前端集成

前端的WebSocket监听器已经自动集成到 `WebSocketEventHub` 中，无需额外配置。

#### 浏览器通知权限

用户可以通过以下方式管理浏览器通知：

1. **自动提示**: 登录用户会在适当时机看到通知权限请求横幅
2. **设置页面**: 可以在设置页面中手动管理通知偏好
3. **测试页面**: 访问 `/websocket-test` 页面进行功能测试

## 测试方法

### 1. 后端测试

运行测试脚本：

```bash
# 命令行测试
php fd-websocket-push/test-websocket-push.php

# 或通过Web访问（需要管理员权限）
https://your-site.com/wp-content/plugins/fd-websocket-push/test-websocket-push.php
```

### 2. 前端测试

访问测试页面：

```
https://your-site.com/websocket-test
```

测试页面提供以下功能：
- WebSocket连接状态监控
- 实时事件日志
- 通知和私信功能测试
- 浏览器通知设置

### 3. 手动测试

#### 测试通知推送

1. 在WordPress后台创建通知
2. 或通过代码调用 `fd_member_notification_create()`
3. 检查前端是否收到实时更新

#### 测试私信推送

1. 发送私信给其他用户
2. 检查接收者是否收到实时更新
3. 验证会话列表是否实时更新

## 故障排除

### 常见问题

1. **WebSocket连接失败**
   - 检查WebSocket服务器是否运行
   - 验证 `NEXT_PUBLIC_WEBSOCKET_URL` 配置
   - 检查防火墙和代理设置

2. **推送事件未发送**
   - 验证 `FD_WEBSOCKET_PUSH_SECRET` 配置
   - 检查WordPress错误日志
   - 确认事件处理器已正确注册

3. **前端未收到事件**
   - 检查浏览器控制台错误
   - 验证用户是否已登录
   - 确认WebSocket连接状态

4. **浏览器通知不工作**
   - 检查浏览器通知权限
   - 验证HTTPS连接（某些浏览器要求）
   - 检查浏览器兼容性

### 调试方法

1. **启用调试日志**
   ```php
   // 在wp-config.php中启用调试
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   ```

2. **查看WebSocket服务器日志**
   ```bash
   # 查看WebSocket服务器输出
   cd fd-websocket
   npm start
   ```

3. **浏览器开发者工具**
   - 检查Network标签中的WebSocket连接
   - 查看Console中的错误信息
   - 监控WebSocket消息流

## 性能优化

### 后端优化

1. **批量通知**: 使用 `fd_member_notification_create_bulk()` 创建批量通知
2. **异步推送**: WebSocket推送使用非阻塞HTTP请求
3. **错误处理**: 推送失败不影响核心功能

### 前端优化

1. **缓存更新**: 使用Apollo Client的精确缓存更新
2. **事件去重**: 避免重复处理相同事件
3. **内存管理**: 及时清理事件监听器

## 扩展开发

### 添加新的WebSocket事件

1. **后端**: 在相应的事件处理器中添加新事件
2. **前端**: 在WebSocket Hook中添加事件监听
3. **测试**: 更新测试脚本和测试页面

### 自定义通知类型

1. 在 `fd_member_notification_get_types()` 中添加新类型
2. 更新前端的类型映射
3. 添加相应的样式和图标

## 版本历史

- **v1.0.0**: 初始版本，支持通知和私信的WebSocket推送
- 集成浏览器通知API
- 添加测试工具和文档

## 技术支持

如有问题，请检查：
1. 本文档的故障排除部分
2. WordPress和WebSocket服务器日志
3. 浏览器开发者工具

---

*最后更新: 2025-08-06*
