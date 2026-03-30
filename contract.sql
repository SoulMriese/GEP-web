CREATE TABLE IF NOT EXISTS `contract_info` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `contract_no` VARCHAR(64) NOT NULL COMMENT '合同编号',
  `contract_name` VARCHAR(255) NOT NULL COMMENT '合同名称',
  `contract_type` VARCHAR(50) DEFAULT NULL COMMENT '合同类型',
  `vendor_name` VARCHAR(255) NOT NULL COMMENT '乙方单位名称',
  `vendor_contact` VARCHAR(100) DEFAULT NULL COMMENT '乙方联系人',
  `vendor_phone` VARCHAR(50) DEFAULT NULL COMMENT '乙方联系电话',
  `contract_amount` DECIMAL(12,2) DEFAULT NULL COMMENT '合同金额',
  `sign_date` DATE DEFAULT NULL COMMENT '签订日期',
  `start_date` DATE DEFAULT NULL COMMENT '生效日期',
  `end_date` DATE DEFAULT NULL COMMENT '终止日期',
  `payment_method` VARCHAR(100) DEFAULT NULL COMMENT '付款方式',
  `contract_status` VARCHAR(30) NOT NULL DEFAULT 'draft' COMMENT '合同状态 draft/active/expired/terminated/archived',
  `manager_user_id` INT DEFAULT NULL COMMENT '经办人ID，对应users.id',
  `content_summary` TEXT COMMENT '合同内容摘要',
  `remark` TEXT COMMENT '备注',
  `original_received` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否收到纸质原件',
  `original_count` INT DEFAULT 0 COMMENT '原件份数',
  `original_location` VARCHAR(255) DEFAULT NULL COMMENT '原件存放位置',
  `archive_status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否归档',
  `archive_date` DATE DEFAULT NULL COMMENT '归档日期',
  `created_by` INT DEFAULT NULL COMMENT '创建人',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` INT DEFAULT NULL COMMENT '最后更新人',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_contract_no` (`contract_no`),
  KEY `idx_vendor_name` (`vendor_name`),
  KEY `idx_contract_status` (`contract_status`),
  KEY `idx_end_date` (`end_date`),
  KEY `idx_manager_user_id` (`manager_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同主表';

CREATE TABLE IF NOT EXISTS `contract_attachment` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `contract_id` INT NOT NULL COMMENT '合同ID',
  `attachment_type` VARCHAR(50) NOT NULL DEFAULT 'contract_file' COMMENT '附件类型 contract_file/original_scan/supplement/invoice/acceptance/other',
  `file_name` VARCHAR(255) NOT NULL COMMENT '原始文件名',
  `stored_name` VARCHAR(255) NOT NULL COMMENT '服务器存储文件名',
  `file_path` VARCHAR(500) NOT NULL COMMENT '文件路径',
  `file_ext` VARCHAR(20) DEFAULT NULL COMMENT '扩展名',
  `file_size` BIGINT DEFAULT 0 COMMENT '文件大小字节',
  `mime_type` VARCHAR(100) DEFAULT NULL COMMENT 'MIME类型',
  `is_original_scan` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否原件扫描件',
  `version_no` INT NOT NULL DEFAULT 1 COMMENT '版本号',
  `uploaded_by` INT DEFAULT NULL COMMENT '上传人',
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `remark` VARCHAR(255) DEFAULT NULL COMMENT '说明',
  PRIMARY KEY (`id`),
  KEY `idx_contract_id` (`contract_id`),
  KEY `idx_attachment_type` (`attachment_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同附件表';

CREATE TABLE IF NOT EXISTS `contract_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `contract_id` INT NOT NULL COMMENT '合同ID',
  `action_type` VARCHAR(50) NOT NULL COMMENT '操作类型 create/update/delete/upload/status_change/view/download/delete_file',
  `action_desc` TEXT COMMENT '操作描述',
  `action_user_id` INT DEFAULT NULL COMMENT '操作人',
  `action_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(64) DEFAULT NULL COMMENT '操作IP',
  PRIMARY KEY (`id`),
  KEY `idx_contract_id` (`contract_id`),
  KEY `idx_action_user_id` (`action_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同操作日志表';

CREATE TABLE IF NOT EXISTS `contract_change` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `contract_id` INT NOT NULL COMMENT '合同ID',
  `change_type` VARCHAR(50) NOT NULL COMMENT '变更类型 amount/date/content/vendor/other',
  `before_value` TEXT COMMENT '变更前',
  `after_value` TEXT COMMENT '变更后',
  `reason` VARCHAR(255) DEFAULT NULL COMMENT '变更原因',
  `effective_date` DATE DEFAULT NULL COMMENT '生效日期',
  `created_by` INT DEFAULT NULL COMMENT '创建人',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contract_id` (`contract_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同变更记录表';

CREATE TABLE IF NOT EXISTS `contract_approval` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `contract_id` INT NOT NULL COMMENT '合同ID',
  `node_name` VARCHAR(100) NOT NULL COMMENT '审批节点名称',
  `approver_user_id` INT DEFAULT NULL COMMENT '审批人',
  `action` VARCHAR(30) DEFAULT NULL COMMENT 'approve/reject/submit',
  `opinion` VARCHAR(500) DEFAULT NULL COMMENT '审批意见',
  `action_time` DATETIME DEFAULT NULL COMMENT '审批时间',
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending' COMMENT 'pending/approved/rejected',
  `sort_no` INT NOT NULL DEFAULT 1 COMMENT '节点顺序',
  PRIMARY KEY (`id`),
  KEY `idx_contract_id` (`contract_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同审批记录表';
