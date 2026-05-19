-- phpMyAdmin SQL Dump
-- version 5.2.1deb3
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: May 12, 2026 at 04:44 PM
-- Server version: 10.11.14-MariaDB-0ubuntu0.24.04.1
-- PHP Version: 8.4.21

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


--
-- Database: `acc_oas`
--

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_category`
--

CREATE TABLE `{prefix}_category` (
  `type` varchar(20) NOT NULL,
  `category_id` varchar(10) NOT NULL DEFAULT '0',
  `language` varchar(2) NOT NULL DEFAULT '',
  `topic` varchar(150) NOT NULL,
  `color` varchar(16) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `{prefix}_category`
--

INSERT INTO `{prefix}_category` (`type`, `category_id`, `language`, `topic`, `color`, `is_active`) VALUES
('category_id', '1', '', 'จอมอนิเตอร์', NULL, 1),
('category_id', '2', '', 'Notebook', NULL, 1),
('category_id', '3', '', 'อุปกรณ์คอมพิวเตอร์', NULL, 1),
('unit', 'UNIT', '', 'อัน', NULL, 1),
('unit', 'PCS', '', 'ตัว', NULL, 1),
('unit', 'SHEET', '', 'ผืน', NULL, 1),
('unit', 'LINE', '', 'เส้น', NULL, 1),
('unit', 'RING', '', 'วง', NULL, 1),
('unit', 'PAIR', '', 'คู่', NULL, 1),
('unit', 'SET', '', 'ชุด', NULL, 1),
('unit', 'BOX', '', 'กล่อง', NULL, 1),
('unit', 'PACK', '', 'แพ็ก', NULL, 1),
('unit', 'BAG', '', 'ใบ', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_customer`
--

CREATE TABLE `{prefix}_customer` (
  `id` int(11) NOT NULL,
  `code` varchar(20) DEFAULT NULL,
  `company` varchar(150) DEFAULT NULL,
  `branch` varchar(50) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `idcard` varchar(13) DEFAULT NULL,
  `tax_id` varchar(13) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `fax` varchar(20) DEFAULT NULL,
  `email` varchar(50) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `province_id` varchar(3) DEFAULT NULL,
  `province` varchar(64) DEFAULT NULL,
  `zipcode` varchar(5) DEFAULT NULL,
  `country` varchar(2) DEFAULT 'TH',
  `note` text DEFAULT NULL,
  `bank_account` varchar(20) DEFAULT NULL,
  `bank_name` varchar(50) DEFAULT NULL,
  `bank_no` varchar(20) DEFAULT NULL,
  `discount` double NOT NULL DEFAULT 0,
  `contact` varchar(150) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `type` enum('customer','supplier') NOT NULL DEFAULT 'customer',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `is_customer` tinyint(1) NOT NULL DEFAULT 1,
  `is_supplier` tinyint(1) NOT NULL DEFAULT 0,
  `line_id` varchar(50) DEFAULT NULL,
  `line_name` varchar(150) DEFAULT NULL,
  `payment_terms` int(11) DEFAULT NULL,
  `payment_type` varchar(20) DEFAULT 'cash',
  `price_group` tinyint(2) NOT NULL DEFAULT 1,
  `bank_branch` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `{prefix}_customer`
--

INSERT INTO `{prefix}_customer` (`id`, `code`, `company`, `branch`, `name`, `idcard`, `tax_id`, `phone`, `fax`, `email`, `address`, `province_id`, `province`, `zipcode`, `country`, `note`, `bank_account`, `bank_name`, `bank_no`, `discount`, `contact`, `is_active`, `type`, `created_at`, `updated_at`, `is_customer`, `is_supplier`, `line_id`, `line_name`, `payment_terms`, `payment_type`, `price_group`, `bank_branch`) VALUES
(2, 'CUS0006', 'ทดสอบ ลูกค้า', '', 'สมชาย สวัสดี', '', '1212123', '0123456789', '', 'admin@email.com', '123/456 ต.ในเมือง อ.เมือง', '10', 'กรุงเทพมหานคร', '10000', 'TH', '', '12345', 'กสิการไทย', NULL, 0, 'สมชาย สวัสดี', 1, 'customer', NULL, '0000-00-00 00:00:00', 1, 0, NULL, NULL, NULL, 'cash', 1, NULL),
(3, 'CUS0008', NULL, NULL, 'ทดสอบ ลูกค้า', NULL, '', '', NULL, '', '', '0', NULL, '', NULL, '', '', '', NULL, 0, '', 1, 'customer', NULL, NULL, 1, 0, NULL, NULL, NULL, 'cash', 1, NULL),
(4, 'SUP0001', NULL, NULL, 'ทดสอบ คู่ค้า', NULL, '', '', NULL, '', '', '0', NULL, '', NULL, '', '', '', NULL, 0, '', 1, 'supplier', NULL, NULL, 1, 0, NULL, NULL, NULL, 'cash', 1, NULL),
(5, 'CUS0011', NULL, NULL, 'สด', NULL, '', '', NULL, '', '', '', NULL, '', 'TH', '', '', '', NULL, 0, '', 1, 'customer', NULL, NULL, 1, 0, NULL, NULL, NULL, 'cash', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_inventory`
--

CREATE TABLE `{prefix}_inventory` (
  `id` int(11) NOT NULL,
  `category_id` varchar(10) DEFAULT NULL,
  `product_code` varchar(150) DEFAULT NULL,
  `topic` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `inuse` tinyint(1) DEFAULT 1,
  `cost` double NOT NULL DEFAULT 0,
  `stockable` tinyint(1) NOT NULL DEFAULT 1,
  `allow_negative` tinyint(1) NOT NULL DEFAULT 0,
  `count_stock` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `{prefix}_inventory`
--

INSERT INTO `{prefix}_inventory` (`id`, `category_id`, `product_code`, `topic`, `description`, `inuse`, `cost`, `stockable`, `allow_negative`, `count_stock`) VALUES
(12, '1', 'SKU0001', 'โบว์ผ้ากำมะหยี่ ให้ความรู้สึกหรูหราและนุ่มนวล', '', 1, 10, 1, 0, 1),
(13, '1', 'SKU0002', 'โบว์แบบริบบิ้นสองชั้น ให้ความรู้สึกแน่นหนาและสง่างามเป็นพิเศษ', 'แสดงความเคารพอย่างเต็มที่ด้วยโบว์ริบบิ้นสองชั้นสีดำ ที่ให้ความรู้สึกแน่นหนาและสง่างามเป็นพิเศษ สะท้อนถึงความมุ่งมั่นและความจริงใจในโอกาสสำคัญอย่างสมบูรณ์แบบ', 1, 10, 1, 0, 1);

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_inventory_cost_allocation`
--

CREATE TABLE `{prefix}_inventory_cost_allocation` (
  `id` int(11) NOT NULL,
  `layer_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `inventory_item_id` int(11) DEFAULT NULL,
  `sku` varchar(150) NOT NULL,
  `movement_id` int(11) DEFAULT NULL,
  `source_allocation_id` int(11) DEFAULT NULL,
  `reference_type` varchar(20) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `reference_item_id` int(11) DEFAULT NULL,
  `quantity` double NOT NULL DEFAULT 0,
  `unit_cost` double NOT NULL DEFAULT 0,
  `total_cost` double NOT NULL DEFAULT 0,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `{prefix}_inventory_cost_allocation`
--

INSERT INTO `{prefix}_inventory_cost_allocation` (`id`, `layer_id`, `inventory_id`, `inventory_item_id`, `sku`, `movement_id`, `source_allocation_id`, `reference_type`, `reference_id`, `reference_no`, `reference_item_id`, `quantity`, `unit_cost`, `total_cost`, `note`, `created_by`, `created_at`) VALUES
(5, 1, 12, 1, 'SKU0001', 9, NULL, 'order', 1, 'RCP6905-0001', 14, 1, 10, 10, 'Order RCP6905-0001', 1, '2026-05-12 13:56:52'),
(6, 1, 12, 1, 'SKU0001', 10, NULL, 'order', 5, 'RCP6905-0005', 15, 9, 10, 90, 'Order RCP6905-0005', 1, '2026-05-12 13:57:07'),
(7, 4, 12, 1, 'SKU0001', 10, NULL, 'order', 5, 'RCP6905-0005', 15, 1, 10, 10, 'Order RCP6905-0005', 1, '2026-05-12 13:57:07');

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_inventory_cost_layer`
--

CREATE TABLE `{prefix}_inventory_cost_layer` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `inventory_item_id` int(11) DEFAULT NULL,
  `sku` varchar(150) NOT NULL,
  `reference_type` varchar(20) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `reference_item_id` int(11) DEFAULT NULL,
  `source_allocation_id` int(11) DEFAULT NULL,
  `received_qty` double NOT NULL DEFAULT 0,
  `remaining_qty` double NOT NULL DEFAULT 0,
  `unit_cost` double NOT NULL DEFAULT 0,
  `currency` varchar(3) NOT NULL DEFAULT 'THB',
  `note` text DEFAULT NULL,
  `received_at` datetime NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `{prefix}_inventory_cost_layer`
--

INSERT INTO `{prefix}_inventory_cost_layer` (`id`, `inventory_id`, `inventory_item_id`, `sku`, `reference_type`, `reference_id`, `reference_no`, `reference_item_id`, `source_allocation_id`, `received_qty`, `remaining_qty`, `unit_cost`, `currency`, `note`, `received_at`, `created_by`, `created_at`) VALUES
(1, 12, 1, 'SKU0001', 'goods_receipt', 0, 'GR-OPEN-12', 0, NULL, 10, 0, 10, 'THB', 'Auto goods receipt from product creation for โบว์ผ้ากำมะหยี่ ให้ความรู้สึกหรูหราและนุ่มนวล', '2026-05-11 19:05:35', 1, '2026-05-11 19:05:35'),
(2, 13, 2, 'SKU0002', 'goods_receipt', 0, 'GR-OPEN-13', 0, NULL, 10, 10, 10, 'THB', 'Auto goods receipt from product creation for โบว์แบบริบบิ้นสองชั้น ให้ความรู้สึกแน่นหนาและสง่างามเป็นพิเศษ', '2026-05-11 19:06:17', 1, '2026-05-11 19:06:17'),
(6, 12, 1, 'SKU0001', 'order', 6, 'RET6905-0006', 16, NULL, 1, 1, 10, 'THB', 'Order RET6905-0006', '2026-05-12 13:57:30', 1, '2026-05-12 13:57:30'),
(7, 12, 1, 'SKU0001', 'order', 3, 'GR6905-0001', 17, NULL, 10, 10, 10, 'THB', 'Order GR6905-0001', '2026-05-12 13:57:47', 1, '2026-05-12 13:57:47');

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_inventory_items`
--

CREATE TABLE `{prefix}_inventory_items` (
  `id` int(11) NOT NULL,
  `sku` varchar(150) NOT NULL,
  `barcode` varchar(150) DEFAULT NULL,
  `inventory_id` int(11) NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `stock` double NOT NULL DEFAULT 0,
  `price` double NOT NULL DEFAULT 0,
  `cut_stock` double NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `{prefix}_inventory_items`
--

INSERT INTO `{prefix}_inventory_items` (`id`, `sku`, `barcode`, `inventory_id`, `unit`, `stock`, `price`, `cut_stock`) VALUES
(1, 'SKU0001', NULL, 12, 'อัน', 10, 38, 1),
(2, 'SKU0002', NULL, 13, 'อัน', 10, 35, 1);

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_inventory_meta`
--

CREATE TABLE `{prefix}_inventory_meta` (
  `inventory_id` int(11) NOT NULL,
  `name` varchar(20) NOT NULL,
  `value` mediumtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_inventory_stock`
--

CREATE TABLE `{prefix}_inventory_stock` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `inventory_item_id` int(11) DEFAULT NULL,
  `sku` varchar(150) NOT NULL,
  `qty` double NOT NULL DEFAULT 0,
  `reserved_qty` double NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `{prefix}_inventory_stock`
--

INSERT INTO `{prefix}_inventory_stock` (`id`, `inventory_id`, `inventory_item_id`, `sku`, `qty`, `reserved_qty`) VALUES
(1, 12, 1, 'SKU0001', 10, 0),
(2, 13, 2, 'SKU0002', 10, 0);

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_inventory_stock_movement`
--

CREATE TABLE `{prefix}_inventory_stock_movement` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `inventory_item_id` int(11) DEFAULT NULL,
  `sku` varchar(150) NOT NULL,
  `movement_direction` enum('in','out') NOT NULL DEFAULT 'in',
  `movement_type` varchar(20) NOT NULL,
  `reference_type` varchar(20) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `reference_item_id` int(11) DEFAULT NULL,
  `source_movement_id` int(11) DEFAULT NULL,
  `quantity` double NOT NULL DEFAULT 0,
  `unit_cost` double DEFAULT NULL,
  `total_cost` double DEFAULT NULL,
  `note` text DEFAULT NULL,
  `occurred_at` datetime NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `{prefix}_inventory_stock_movement`
--

INSERT INTO `{prefix}_inventory_stock_movement` (`id`, `inventory_id`, `inventory_item_id`, `sku`, `movement_direction`, `movement_type`, `reference_type`, `reference_id`, `reference_no`, `reference_item_id`, `source_movement_id`, `quantity`, `unit_cost`, `total_cost`, `note`, `occurred_at`, `created_by`, `created_at`) VALUES
(1, 12, 1, 'SKU0001', 'in', 'receipt', 'goods_receipt', 0, 'GR-OPEN-12', 0, NULL, 10, 10, 100, 'Auto goods receipt from product creation for โบว์ผ้ากำมะหยี่ ให้ความรู้สึกหรูหราและนุ่มนวล', '2026-05-11 19:05:35', 1, '2026-05-11 19:05:35'),
(2, 13, 2, 'SKU0002', 'in', 'receipt', 'goods_receipt', 0, 'GR-OPEN-13', 0, NULL, 10, 10, 100, 'Auto goods receipt from product creation for โบว์แบบริบบิ้นสองชั้น ให้ความรู้สึกแน่นหนาและสง่างามเป็นพิเศษ', '2026-05-11 19:06:17', 1, '2026-05-11 19:06:17'),
(9, 12, 1, 'SKU0001', 'out', 'sale', 'order', 1, 'RCP6905-0001', 14, NULL, 1, 10, 10, 'Order RCP6905-0001', '2026-05-12 13:56:52', 1, '2026-05-12 13:56:52'),
(10, 12, 1, 'SKU0001', 'out', 'sale', 'order', 5, 'RCP6905-0005', 15, NULL, 10, 10, 100, 'Order RCP6905-0005', '2026-05-12 13:57:07', 1, '2026-05-12 13:57:07'),
(11, 12, 1, 'SKU0001', 'in', 'purchase', 'order', 6, 'RET6905-0006', 16, NULL, 1, 10, 10, 'Order RET6905-0006', '2026-05-12 13:57:30', 1, '2026-05-12 13:57:30'),
(12, 12, 1, 'SKU0001', 'in', 'purchase', 'order', 3, 'GR6905-0001', 17, NULL, 10, 10, 100, 'Order GR6905-0001', '2026-05-12 13:57:47', 1, '2026-05-12 13:57:47');

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_language`
--

CREATE TABLE `{prefix}_language` (
  `id` int(11) NOT NULL,
  `key` mediumtext NOT NULL,
  `type` varchar(5) NOT NULL,
  `th` mediumtext DEFAULT NULL,
  `en` mediumtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_logs`
--

CREATE TABLE `{prefix}_logs` (
  `id` int(11) NOT NULL,
  `src_id` int(11) NOT NULL,
  `module` varchar(20) NOT NULL,
  `action` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL,
  `reason` mediumtext DEFAULT NULL,
  `member_id` int(11) NOT NULL,
  `topic` mediumtext NOT NULL,
  `datas` mediumtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_number`
--

CREATE TABLE `{prefix}_number` (
  `type` varchar(20) NOT NULL,
  `prefix` varchar(20) NOT NULL,
  `auto_increment` int(11) NOT NULL,
  `updated_at` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_order`
--

CREATE TABLE `{prefix}_order` (
  `id` int(11) NOT NULL,
  `order_no` varchar(50) NOT NULL,
  `document_type` varchar(10) NOT NULL DEFAULT 'QT',
  `document_status` enum('draft','issued','cancelled') NOT NULL DEFAULT 'issued',
  `payment_status` varchar(20) NOT NULL DEFAULT 'paid',
  `source_document_id` int(11) DEFAULT NULL,
  `root_document_id` int(11) DEFAULT NULL,
  `reference_document_no` varchar(50) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `customer_tax_id` varchar(13) DEFAULT NULL,
  `customer_email` varchar(50) DEFAULT NULL,
  `customer_contact` varchar(150) DEFAULT NULL,
  `customer_company` varchar(150) DEFAULT NULL,
  `customer_address` text DEFAULT NULL,
  `customer_province` varchar(64) DEFAULT NULL,
  `customer_zipcode` varchar(5) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `subtotal` double NOT NULL DEFAULT 0,
  `discount_amount` double NOT NULL DEFAULT 0,
  `tax_amount` double NOT NULL DEFAULT 0,
  `tax_rate` double NOT NULL DEFAULT 0,
  `shipping_cost` double NOT NULL DEFAULT 0,
  `total` double NOT NULL DEFAULT 0,
  `paid_amount` double NOT NULL DEFAULT 0,
  `change_amount` double NOT NULL DEFAULT 0,
  `currency` varchar(3) NOT NULL DEFAULT 'THB',
  `payment_method` varchar(20) DEFAULT NULL,
  `payment_ref` varchar(100) DEFAULT NULL,
  `issued_at` datetime DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `note` text DEFAULT NULL,
  `internal_note` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `{prefix}_order`
--

INSERT INTO `{prefix}_order` (`id`, `order_no`, `document_type`, `document_status`, `payment_status`, `source_document_id`, `root_document_id`, `reference_document_no`, `customer_id`, `customer_name`, `customer_phone`, `customer_tax_id`, `customer_email`, `customer_contact`, `customer_company`, `customer_address`, `customer_province`, `customer_zipcode`, `member_id`, `subtotal`, `discount_amount`, `tax_amount`, `tax_rate`, `shipping_cost`, `total`, `paid_amount`, `change_amount`, `currency`, `payment_method`, `payment_ref`, `issued_at`, `due_date`, `paid_at`, `completed_at`, `cancelled_at`, `note`, `internal_note`, `created_at`, `updated_at`) VALUES
(1, 'RCP6905-0001', 'RCP', 'issued', 'paid', NULL, 1, NULL, 5, 'สดจ้า', '', '', '', '', NULL, '', NULL, '', 1, 38, 0, 0, 0, 0, 38, 0, 0, 'THB', NULL, NULL, '2026-05-12 13:56:52', NULL, '2026-05-12 13:56:52', '2026-05-11 19:54:39', NULL, '', '', '2026-05-11 19:30:37', '2026-05-12 13:56:52'),
(2, 'PO6905-0001', 'PO', 'issued', 'paid', NULL, 2, NULL, 5, 'สดจ้า', '', '', '', '', NULL, '', NULL, '', 1, 100, 0, 0, 0, 0, 100, 0, 0, 'THB', NULL, NULL, '2026-05-12 13:55:20', NULL, '2026-05-12 13:55:20', '2026-05-12 10:51:20', NULL, '', '', '2026-05-11 19:59:45', '2026-05-12 13:55:20'),
(3, 'GR6905-0001', 'GR', 'issued', 'paid', 2, 2, NULL, 4, 'ทดสอบ คู่ค้า', '', '', '', '', NULL, '', NULL, '', 1, 100, 0, 0, 0, 0, 100, 0, 0, 'THB', NULL, NULL, '2026-05-12 13:57:47', NULL, '2026-05-12 13:57:47', '2026-05-11 22:49:17', NULL, '', '', '2026-05-11 22:44:50', '2026-05-12 13:57:47'),
(4, 'QT6905-0004', 'QT', 'issued', 'paid', NULL, 4, NULL, 2, 'สมชาย สวัสดี', '0123456789', '1212123', 'admin@email.com', 'สมชาย สวัสดี', 'ทดสอบ ลูกค้า', '123/456 ต.ในเมือง อ.เมือง', 'กรุงเทพมหานคร', '10000', 1, 380, 0, 0, 0, 0, 380, 0, 0, 'THB', NULL, NULL, '2026-05-12 13:41:31', NULL, '2026-05-12 13:41:31', '2026-05-12 09:19:34', NULL, '', '', '2026-05-12 09:19:34', '2026-05-12 13:41:31'),
(5, 'RCP6905-0005', 'RCP', 'issued', 'paid', 4, 4, NULL, 5, 'สดจ้า', '', '', '', '', NULL, '', NULL, '', 1, 380, 0, 0, 0, 0, 380, 0, 0, 'THB', NULL, NULL, '2026-05-12 13:57:07', NULL, '2026-05-12 13:57:07', '2026-05-12 10:10:10', NULL, '', '', '2026-05-12 10:10:10', '2026-05-12 13:57:07'),
(6, 'RET6905-0006', 'RET', 'issued', 'paid', 1, 1, NULL, 4, 'ทดสอบ คู่ค้า', '', '', '', '', NULL, '', NULL, '', 1, 38, 0, 0, 0, 0, 38, 0, 0, 'THB', NULL, NULL, '2026-05-12 13:57:30', NULL, '2026-05-12 13:57:30', '2026-05-12 11:03:17', NULL, '', '', '2026-05-12 11:03:17', '2026-05-12 13:57:30');

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_order_item`
--

CREATE TABLE `{prefix}_order_item` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `source_item_id` int(11) DEFAULT NULL,
  `root_item_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `inventory_item_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `product_code` varchar(150) NOT NULL,
  `name` varchar(255) NOT NULL,
  `quantity` double NOT NULL DEFAULT 1,
  `unit` varchar(50) DEFAULT NULL,
  `unit_price` double NOT NULL DEFAULT 0,
  `cost_price` double DEFAULT NULL,
  `discount_amount` double NOT NULL DEFAULT 0,
  `tax_amount` double NOT NULL DEFAULT 0,
  `subtotal` double NOT NULL DEFAULT 0,
  `note` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `{prefix}_order_item`
--

INSERT INTO `{prefix}_order_item` (`id`, `order_id`, `source_item_id`, `root_item_id`, `product_id`, `inventory_item_id`, `item_id`, `product_code`, `name`, `quantity`, `unit`, `unit_price`, `cost_price`, `discount_amount`, `tax_amount`, `subtotal`, `note`) VALUES
(12, 4, NULL, NULL, 12, 1, 0, 'SKU0001', 'โบว์ผ้ากำมะหยี่ ให้ความรู้สึกหรูหราและนุ่มนวล', 10, 'อัน', 38, 10, 0, 0, 380, ''),
(13, 2, NULL, NULL, 12, 1, 0, 'SKU0001', 'โบว์ผ้ากำมะหยี่ ให้ความรู้สึกหรูหราและนุ่มนวล', 10, 'อัน', 10, 10, 0, 0, 100, ''),
(14, 1, NULL, NULL, 12, 1, 0, 'SKU0001', 'โบว์ผ้ากำมะหยี่ ให้ความรู้สึกหรูหราและนุ่มนวล', 1, 'อัน', 38, 10, 0, 0, 38, ''),
(15, 5, NULL, NULL, 12, 1, 0, 'SKU0001', 'โบว์ผ้ากำมะหยี่ ให้ความรู้สึกหรูหราและนุ่มนวล', 10, 'อัน', 38, 10, 0, 0, 380, ''),
(16, 6, NULL, NULL, 12, 1, 0, 'SKU0001', 'โบว์ผ้ากำมะหยี่ ให้ความรู้สึกหรูหราและนุ่มนวล', 1, 'อัน', 38, 10, 0, 0, 38, ''),
(17, 3, NULL, NULL, 12, 1, 0, 'SKU0001', 'โบว์ผ้ากำมะหยี่ ให้ความรู้สึกหรูหราและนุ่มนวล', 10, 'อัน', 10, 10, 0, 0, 100, '');

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_user`
--

CREATE TABLE `{prefix}_user` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `salt` varchar(32) NOT NULL,
  `password` varchar(64) NOT NULL,
  `token` varchar(512) DEFAULT NULL,
  `token_expires` datetime DEFAULT NULL,
  `status` tinyint(1) DEFAULT 0,
  `permission` mediumtext DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `sex` varchar(1) DEFAULT NULL,
  `id_card` varchar(13) DEFAULT NULL,
  `address` varchar(64) DEFAULT NULL,
  `address2` varchar(64) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `phone1` varchar(20) DEFAULT NULL,
  `fax` varchar(32) DEFAULT NULL,
  `provinceID` smallint(3) DEFAULT NULL,
  `province` varchar(64) DEFAULT NULL,
  `zipcode` varchar(5) DEFAULT NULL,
  `country` varchar(2) DEFAULT 'TH',
  `created_at` datetime NOT NULL,
  `active` tinyint(1) DEFAULT 0,
  `social` enum('user','facebook','google','line','telegram') DEFAULT 'user',
  `email` varchar(50) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `tax_id` varchar(13) DEFAULT NULL,
  `type` tinyint(1) NOT NULL DEFAULT 0,
  `line_uid` varchar(33) DEFAULT NULL,
  `telegram_id` varchar(20) DEFAULT NULL,
  `activatecode` varchar(64) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `company` varchar(64) DEFAULT NULL,
  `visited` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `{prefix}_user_meta`
--

CREATE TABLE `{prefix}_user_meta` (
  `value` varchar(10) NOT NULL,
  `name` varchar(20) NOT NULL,
  `member_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `{prefix}_category`
--
ALTER TABLE `{prefix}_category`
  ADD KEY `type` (`type`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `language` (`language`);

--
-- Indexes for table `{prefix}_customer`
--
ALTER TABLE `{prefix}_customer`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `customer_no` (`code`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `type` (`type`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_partner_roles` (`is_active`,`is_customer`,`is_supplier`),
  ADD KEY `idx_name` (`name`),
  ADD KEY `idx_company` (`company`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_email` (`email`);

--
-- Indexes for table `{prefix}_inventory`
--
ALTER TABLE `{prefix}_inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_code` (`product_code`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `{prefix}_inventory_cost_allocation`
--
ALTER TABLE `{prefix}_inventory_cost_allocation`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_layer` (`layer_id`),
  ADD KEY `inventory_item_id` (`inventory_item_id`),
  ADD KEY `idx_source_allocation` (`source_allocation_id`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`),
  ADD KEY `idx_inventory_product` (`inventory_id`,`sku`),
  ADD KEY `idx_movement` (`movement_id`);

--
-- Indexes for table `{prefix}_inventory_cost_layer`
--
ALTER TABLE `{prefix}_inventory_cost_layer`
  ADD PRIMARY KEY (`id`),
  ADD KEY `inventory_item_id` (`inventory_item_id`),
  ADD KEY `idx_inventory_product_open` (`inventory_id`,`sku`,`remaining_qty`,`received_at`),
  ADD KEY `idx_source_allocation` (`source_allocation_id`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`),
  ADD KEY `idx_received_at` (`received_at`);

--
-- Indexes for table `{prefix}_inventory_items`
--
ALTER TABLE `{prefix}_inventory_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD UNIQUE KEY `barcode` (`barcode`),
  ADD KEY `inventory_id` (`inventory_id`);

--
-- Indexes for table `{prefix}_inventory_meta`
--
ALTER TABLE `{prefix}_inventory_meta`
  ADD KEY `inventory_id` (`inventory_id`),
  ADD KEY `name` (`name`);

--
-- Indexes for table `{prefix}_inventory_stock`
--
ALTER TABLE `{prefix}_inventory_stock`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `warehouse_product` (`inventory_id`,`sku`) USING BTREE,
  ADD KEY `inventory_item_id` (`inventory_item_id`);

--
-- Indexes for table `{prefix}_inventory_stock_movement`
--
ALTER TABLE `{prefix}_inventory_stock_movement`
  ADD PRIMARY KEY (`id`),
  ADD KEY `inventory_item_id` (`inventory_item_id`),
  ADD KEY `idx_inventory_product` (`inventory_id`,`sku`),
  ADD KEY `idx_direction_type` (`movement_direction`,`movement_type`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`),
  ADD KEY `idx_source_movement` (`source_movement_id`),
  ADD KEY `idx_occurred_at` (`occurred_at`);

--
-- Indexes for table `{prefix}_language`
--
ALTER TABLE `{prefix}_language`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `{prefix}_logs`
--
ALTER TABLE `{prefix}_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `src_id` (`src_id`),
  ADD KEY `module` (`module`),
  ADD KEY `action` (`action`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `{prefix}_number`
--
ALTER TABLE `{prefix}_number`
  ADD PRIMARY KEY (`type`,`prefix`);

--
-- Indexes for table `{prefix}_order`
--
ALTER TABLE `{prefix}_order`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_no` (`order_no`),
  ADD KEY `document_type` (`document_type`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `source_document_id` (`source_document_id`),
  ADD KEY `root_document_id` (`root_document_id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `{prefix}_order_item`
--
ALTER TABLE `{prefix}_order_item`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `inventory_item_id` (`inventory_item_id`),
  ADD KEY `source_item_id` (`source_item_id`),
  ADD KEY `root_item_id` (`root_item_id`);

--
-- Indexes for table `{prefix}_user`
--
ALTER TABLE `{prefix}_user`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `token` (`token`),
  ADD UNIQUE KEY `id_card` (`id_card`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD KEY `idx_status` (`active`,`status`),
  ADD KEY `activatecode` (`activatecode`),
  ADD KEY `line_uid` (`line_uid`),
  ADD KEY `telegram_id` (`telegram_id`);

--
-- Indexes for table `{prefix}_user_meta`
--
ALTER TABLE `{prefix}_user_meta`
  ADD KEY `member_id` (`member_id`,`name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `{prefix}_customer`
--
ALTER TABLE `{prefix}_customer`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `{prefix}_inventory`
--
ALTER TABLE `{prefix}_inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `{prefix}_inventory_cost_allocation`
--
ALTER TABLE `{prefix}_inventory_cost_allocation`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `{prefix}_inventory_cost_layer`
--
ALTER TABLE `{prefix}_inventory_cost_layer`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `{prefix}_inventory_items`
--
ALTER TABLE `{prefix}_inventory_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `{prefix}_inventory_stock`
--
ALTER TABLE `{prefix}_inventory_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `{prefix}_inventory_stock_movement`
--
ALTER TABLE `{prefix}_inventory_stock_movement`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `{prefix}_language`
--
ALTER TABLE `{prefix}_language`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `{prefix}_logs`
--
ALTER TABLE `{prefix}_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `{prefix}_order`
--
ALTER TABLE `{prefix}_order`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `{prefix}_order_item`
--
ALTER TABLE `{prefix}_order_item`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `{prefix}_user`
--
ALTER TABLE `{prefix}_user`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
