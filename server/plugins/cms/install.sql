-- HwAdmin CMS 官方标准示范插件安装脚本 (MySQL 8.0+)

CREATE TABLE IF NOT EXISTS `__PREFIX__cms_category` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pid` INT NOT NULL DEFAULT 0 COMMENT '父级ID',
  `name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '分类名称',
  `slug` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '分类标识',
  `type` VARCHAR(20) NOT NULL DEFAULT 'article' COMMENT '分类类别: article资讯/product商品/solution方案',
  `template_scope` VARCHAR(20) NOT NULL DEFAULT 'all' COMMENT '适用模板: all/blog/corporate/showcase',
  `icon` VARCHAR(64) NOT NULL DEFAULT 'folder-line' COMMENT '图标',
  `description` VARCHAR(300) NOT NULL DEFAULT '' COMMENT '分类描述',
  `weight` INT NOT NULL DEFAULT 0 COMMENT '权重排序',
  `status` INT NOT NULL DEFAULT 1 COMMENT '状态: 0禁用 1启用',
  `create_time` BIGINT NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` BIGINT NOT NULL DEFAULT 0 COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='CMS栏目分类表';

CREATE TABLE IF NOT EXISTS `__PREFIX__cms_article` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT NOT NULL DEFAULT 0 COMMENT '分类ID',
  `title` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '标题/商品名称',
  `summary` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '摘要/简述',
  `content` TEXT COMMENT '正文详情/图文详情',
  `cover` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '主图封面/商品首图',
  `content_type` VARCHAR(20) NOT NULL DEFAULT 'article' COMMENT '内容类型: article文章/product商品/solution方案案例',
  `author` VARCHAR(64) NOT NULL DEFAULT 'Admin' COMMENT '作者/发布人',
  `tags` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '标签列表(逗号分隔)',
  `is_recommend` INT NOT NULL DEFAULT 0 COMMENT '是否推荐 1是 0否',
  `is_hot` INT NOT NULL DEFAULT 0 COMMENT '是否热门 1是 0否',
  `price_label` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '展示价格标签(如 ¥4,999 或 商务询价)',
  `market_price_label` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '划线参考市场价(如 ¥5,999)',
  `sku_code` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '型号/SKU编码',
  `badge_text` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '特性角标(如 2026旗舰/严选爆款/政企首选)',
  `specs_json` TEXT COMMENT '规格参数JSON数组 [{"label":"架构","value":"ARMv9"}]',
  `gallery_json` TEXT COMMENT '多图相册JSON数组',
  `external_link` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '外部链接/演示地址',
  `likes` INT NOT NULL DEFAULT 0 COMMENT '点赞数',
  `views` INT NOT NULL DEFAULT 0 COMMENT '浏览量',
  `weight` INT NOT NULL DEFAULT 0 COMMENT '权重排序',
  `status` INT NOT NULL DEFAULT 1 COMMENT '状态: 0下架 1正常',
  `create_time` BIGINT NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` BIGINT NOT NULL DEFAULT 0 COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='CMS内容与商品表';

CREATE TABLE IF NOT EXISTS `__PREFIX__cms_banner` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `template_code` VARCHAR(20) NOT NULL DEFAULT 'all' COMMENT '适用模板: all/blog/corporate/showcase',
  `title` VARCHAR(150) NOT NULL DEFAULT '' COMMENT '横幅主标题',
  `subtitle` VARCHAR(300) NOT NULL DEFAULT '' COMMENT '横幅副标题',
  `badge` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '顶部小徽标',
  `image` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '背景图/大图',
  `btn_text` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '行动按钮文本',
  `btn_link` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '行动按钮跳转链接',
  `weight` INT NOT NULL DEFAULT 100 COMMENT '权重排序',
  `status` INT NOT NULL DEFAULT 1 COMMENT '状态: 0下架 1发布',
  `create_time` BIGINT NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` BIGINT NOT NULL DEFAULT 0 COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='CMS网站横幅表';

CREATE TABLE IF NOT EXISTS `__PREFIX__cms_inquiry` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `scene` VARCHAR(20) NOT NULL DEFAULT 'general' COMMENT '场景: blog_comment博客留言/corp_contact商务咨询/product_inquiry产品询价',
  `target_id` INT NOT NULL DEFAULT 0 COMMENT '关联对象ID(如商品ID/文章ID)',
  `target_title` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '关联对象名称',
  `user_id` INT NOT NULL DEFAULT 0 COMMENT '关联会员ID(0表示游客)',
  `user_nickname` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '会员昵称',
  `name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '联系人姓名/昵称',
  `contact` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '联系方式(手机/微信/邮箱)',
  `company` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '所属企业/单位',
  `content` TEXT COMMENT '留言或询单具体需求说明',
  `reply_content` TEXT COMMENT '管理员官方答复',
  `status` INT NOT NULL DEFAULT 0 COMMENT '处理状态: 0待处理 1已答复',
  `ip` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '提交者IP',
  `create_time` BIGINT NOT NULL DEFAULT 0 COMMENT '提交时间',
  `update_time` BIGINT NOT NULL DEFAULT 0 COMMENT '处理时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='CMS网站留言与询单表';
