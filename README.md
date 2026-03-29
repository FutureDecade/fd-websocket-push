# FD WebSocket Push Plugin

这是一个重构后的WordPress插件，用于处理在特定WordPress事件发生时向WebSocket服务器发送实时推送通知。

## 插件结构

```
fd-websocket-push/
├── fd-websocket-push.php              # 主插件文件
├── includes/
│   ├── class-helper.php               # 辅助工具类
│   ├── class-cache-invalidator.php    # 缓存失效处理类
│   ├── class-websocket-pusher.php     # WebSocket推送处理类
│   ├── class-post-event-handler.php   # 文章事件处理类
│   ├── class-taxonomy-event-handler.php # 分类法事件处理类
│   └── class-payment-event-handler.php  # 支付事件处理类
└── README.md                          # 说明文档
```

## 功能模块

### 1. 主插件文件 (fd-websocket-push.php)
- 插件初始化和配置
- 加载所有依赖文件
- 管理插件生命周期

### 2. 辅助工具类 (class-helper.php)
- 通用辅助函数
- 配置检查和验证
- 日志记录功能
- 权限和访问级别判断

### 3. 缓存失效处理类 (class-cache-invalidator.php)
- Next.js缓存失效处理
- 标签和路径缓存失效
- 文章和分类法相关缓存管理

### 4. WebSocket推送处理类 (class-websocket-pusher.php)
- WebSocket事件发送
- 事件数据格式化
- 目标房间管理

### 5. 文章事件处理类 (class-post-event-handler.php)
- 文章更新、插入、删除事件
- 文章状态变化事件
- 文章列表更新通知

### 6. 分类法事件处理类 (class-taxonomy-event-handler.php)
- 标签、分类、自定义分类法事件
- 分类法元数据更新
- 文章分类法关联变化
- ACF字段更新支持

### 7. 支付事件处理类 (class-payment-event-handler.php)
- 文章解锁支付成功事件
- 用户精准推送通知

## 配置要求

插件需要在 `wp-config.php` 中定义以下常量：

```php
// WebSocket推送密钥
define( 'FD_WEBSOCKET_PUSH_SECRET', 'your-websocket-secret' );

// Next.js缓存失效密钥
define( 'REVALIDATE_SECRET', 'your-revalidate-secret' );
```

## 事件类型

### WebSocket事件
- `post:updated` - 文章更新
- `post:updated-for-lists` - 文章列表更新
- `post:inserted` - 新文章发布
- `post:deleted` - 文章删除
- `post:status-published` - 文章发布状态变化
- `post:status-unpublished` - 文章取消发布
- `post:unlocked` - 文章解锁成功
- `tag:updated` - 标签更新
- `category:updated` - 分类更新
- `taxonomy:updated` - 自定义分类法更新
- `list:item-added` - 列表项目添加
- `list:item-removed` - 列表项目移除

### 缓存失效标签
- `post:{shortUuid}` - 文章详情页
- `page:{slug}` - 页面
- `category:{slug}` - 分类页
- `tag:{slug}` - 标签页
- `taxonomy:{taxonomy}` - 分类法索引页
- `taxonomy-term:{taxonomy}:{slug}` - 分类法条目页
- `homepage-posts` - 首页文章列表

## 兼容性

- 支持WordPress内置分类法（分类、标签）
- 支持自定义分类法
- 支持ACF（Advanced Custom Fields）插件
- 兼容fd-payment插件的支付事件

## 从旧版本迁移

如果您从旧的fd-pusher插件迁移：

1. 停用旧的fd-pusher插件
2. 激活新的fd-websocket-push插件
3. 确认所有功能正常工作
4. 可选择删除旧插件文件

插件功能完全兼容，无需修改配置。

## 向后兼容性

为了确保平滑迁移，插件提供了以下向后兼容的全局函数：

- `fd_pusher_revalidate_tag( $tag )` - 缓存标签失效
- `fd_pusher_revalidate_tag_for_term( $term )` - 分类法条目缓存失效

这些函数会自动调用新的类方法，确保任何依赖旧函数名的代码继续正常工作。

## 重构说明

本次重构的主要改进：

1. **模块化设计** - 将单一大文件拆分为多个专门的类文件
2. **单例模式** - 确保每个处理器只有一个实例
3. **职责分离** - 每个类专注于特定的功能领域
4. **更好的维护性** - 代码结构更清晰，便于后续扩展和维护
5. **向后兼容** - 保持与旧版本的API兼容性

重构后的代码功能与原版本完全一致，但结构更加清晰和可维护。

## 最新更新

### v1.0.1 (2025-07-18)

**🐛 错误修复：**
- 修复 `class-taxonomy-event-handler.php` 第433行多余的右大括号导致的PHP解析错误
- 修复文章状态更新后，category、tag和自定义分类法条目索引页的文章数量没有同步刷新的问题

**🔧 改进项目：**
- 完善缓存失效逻辑：在文章插入、删除、状态变化时正确调用分类法缓存失效方法
- 增强WebSocket事件通知：添加分类法更新的WebSocket事件通知，确保前端页面能及时更新文章数量
- 优化事件处理流程：改进文章状态变化时的事件处理逻辑，确保所有相关页面都能收到更新通知

**📝 技术细节：**
- 修复的文件：`includes/class-taxonomy-event-handler.php`、`includes/class-cache-invalidator.php`
- 新增方法：`notify_taxonomy_updates_for_post()` - 发送分类法更新的WebSocket事件
- 改进的方法：`invalidate_caches_on_post_insert()`、`invalidate_caches_on_post_delete()`、`invalidate_caches_on_post_status_change()`

**🎯 影响范围：**
- 分类法索引页（category-index-page、tag-index-page等）现在能正确更新文章数量
- 分类法条目页（具体的分类、标签、自定义分类法页面）能正确更新文章数量
- WebSocket事件：前端能收到分类法更新事件，实现实时刷新
- 缓存失效：Next.js缓存能正确失效，确保数据一致性

**⚡ 升级说明：**
- 无需配置变更：此版本为纯错误修复，无需修改任何配置
- 即时生效：更新插件后立即生效，无需额外操作
- 向后兼容：完全兼容现有功能，不影响已有的事件处理

## 文档

完整的文档请查看 [docs/](docs/) 目录，包含：
- 安装配置指南
- API参考文档
- 事件系统说明
- 缓存管理详解
- 故障排除指南
- 使用示例
