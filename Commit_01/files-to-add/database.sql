-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 13, 2026 at 07:33 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `brille_amour`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `admin_code` varchar(30) DEFAULT NULL,
  `position` varchar(100) DEFAULT 'Administrator',
  `permissions` text DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_activity_log`
--

CREATE TABLE `admin_activity_log` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `description`, `status`, `created_at`) VALUES
(5, 'Printing Products', 'Finished printed products such as tarpaulins, flyers, business cards, and invitations.', 'active', '2026-04-08 01:43:40'),
(6, 'Craft Products', 'Finished handmade and craft items such as scrapbooks, photo albums, and party decorations.', 'active', '2026-04-08 01:43:40'),
(7, 'Print & Craft Best Sellers', 'Top-selling customized items such as mugs, t-shirts, tote bags, and sticker packs.', 'active', '2026-04-08 01:43:40'),
(8, 'Event-based Products', 'Packages and items for special events such as birthdays, weddings, graduations, and baptisms.', 'active', '2026-04-08 01:43:40');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `quantity` int(11) DEFAULT 0,
  `unit` varchar(30) DEFAULT 'pcs',
  `reorder_level` int(11) DEFAULT 10,
  `unit_cost` decimal(10,2) DEFAULT 0.00,
  `status` enum('active','archived') DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `category_id`, `name`, `sku`, `description`, `quantity`, `unit`, `reorder_level`, `unit_cost`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(77, 5, 'Tarpaulin / Banner', 'PRT-001', 'Custom printed tarpaulins and banners for events, promotions, and signage.', 20, 'pcs', 10, 150.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(78, 5, 'Flyers', 'PRT-002', 'Printed flyers for promotions, announcements, and advertisements.', 195, 'pcs', 50, 5.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-12 16:35:16'),
(79, 5, 'Brochures', 'PRT-003', 'Folded printed brochures for marketing and information distribution.', 90, 'pcs', 50, 20.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-13 04:37:36'),
(80, 5, 'Posters', 'PRT-004', 'Large format printed posters for events and promotions.', 50, 'pcs', 20, 80.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(81, 5, 'Business Cards', 'PRT-005', 'Professional business cards with custom design and printing.', 281, 'pcs', 100, 3.50, 'active', NULL, '2026-04-08 01:43:40', '2026-04-13 05:30:15'),
(82, 5, 'Invitations - Wedding', 'PRT-006', 'Custom printed wedding invitations with personalized design.', 50, 'pcs', 20, 35.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(83, 5, 'Invitations - Birthday', 'PRT-007', 'Custom printed birthday party invitations with personalized design.', 50, 'pcs', 20, 25.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(84, 5, 'Stickers', 'PRT-008', 'Custom printed stickers in various sizes and finishes.', 100, 'pcs', 50, 10.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(85, 5, 'Labels', 'PRT-009', 'Custom printed product or packaging labels.', 95, 'pcs', 50, 8.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-12 16:28:39'),
(86, 5, 'IDs', 'PRT-010', 'Printed identification cards for organizations, schools, and events.', 50, 'pcs', 20, 50.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(87, 5, 'Lanyards', 'PRT-011', 'Custom printed or plain lanyards for ID holders.', 50, 'pcs', 20, 40.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(88, 5, 'Photocopy & Document Printing', 'PRT-012', 'Black and white or color photocopying and document printing service.', 100, 'pcs', 10, 3.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(89, 5, 'Bookbinding / Thesis Printing', 'PRT-013', 'Thesis, reports, and document bookbinding and printing service.', 20, 'pcs', 5, 250.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(90, 6, 'Scrapbook', 'CRF-001', 'Handcrafted scrapbooks for memories, photos, and keepsakes.', 15, 'pcs', 5, 350.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(91, 6, 'Photo Album', 'CRF-002', 'Handmade or customized photo albums for printing and preserving photos.', 15, 'pcs', 5, 300.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(92, 6, 'Handmade Greeting Cards', 'CRF-003', 'Custom handmade greeting cards for birthdays, holidays, and special occasions.', 30, 'pcs', 10, 50.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(93, 6, 'Party Banners', 'CRF-004', 'Decorative party banners for birthdays, celebrations, and events.', 25, 'pcs', 10, 120.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(94, 6, 'Party Cutouts', 'CRF-005', 'Custom party cutout decorations for events and celebrations.', 25, 'pcs', 10, 80.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(95, 7, 'Customized Mug', 'BES-001', 'Personalized mugs with custom printed designs, photos, or text.', 30, 'pcs', 10, 250.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(96, 7, 'T-Shirt Printing', 'BES-002', 'Custom design printing on t-shirts using DTF, sublimation, or screen printing.', 25, 'pcs', 10, 350.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(97, 7, 'Tote Bag with Print', 'BES-003', 'Canvas or non-woven tote bags with custom printed designs.', 25, 'pcs', 10, 150.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(98, 7, 'Ref Magnet / Souvenir Giveaway', 'BES-004', 'Custom printed refrigerator magnets as souvenirs or giveaways.', 50, 'pcs', 20, 35.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(99, 7, 'Personalized Notebook / Journal', 'BES-005', 'Custom cover notebooks and journals with personalized printing.', 30, 'pcs', 10, 180.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(100, 7, 'Photo Frame with Printed Picture', 'BES-006', 'Photo frames paired with custom printed pictures or designs.', 20, 'pcs', 10, 200.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(101, 7, 'Sticker Pack - Aesthetic', 'BES-007', 'Aesthetic-themed sticker packs for personal use, journaling, and decoration.', 50, 'pcs', 20, 49.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(102, 7, 'Sticker Pack - K-Pop', 'BES-008', 'K-Pop themed sticker packs for fans and collectors.', 50, 'pcs', 20, 49.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(103, 7, 'Sticker Pack - Anime', 'BES-009', 'Anime-themed sticker packs for fans and collectors.', 50, 'pcs', 20, 49.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(104, 8, 'Birthday Package', 'EVT-001', 'Complete birthday package including tarpaulin, souvenirs, and invitations.', 7, 'set', 5, 999.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-11 04:07:56'),
(105, 8, 'Wedding Package', 'EVT-002', 'Complete wedding package including invites, giveaways, and signage.', 10, 'set', 5, 2500.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(106, 8, 'Graduation Tarpaulin', 'EVT-003', 'Custom tarpaulin for graduation celebrations and ceremonies.', 15, 'pcs', 5, 200.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(107, 8, 'Graduation Medal', 'EVT-004', 'Custom medals for graduation recognition and awards.', 30, 'pcs', 10, 85.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(108, 8, 'Graduation Certificate', 'EVT-005', 'Custom printed graduation certificates with personalized details.', 30, 'pcs', 10, 50.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(109, 8, 'Baby Shower Souvenir', 'EVT-006', 'Custom souvenir items for baby shower events and celebrations.', 30, 'pcs', 10, 75.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-11 07:32:20'),
(110, 8, 'Baptism Souvenir', 'EVT-007', 'Custom souvenir items for baptism and christening events.', 25, 'pcs', 10, 75.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(111, 8, 'Customized Frame', 'EVT-008', 'Personalized frames for events, milestones, and commemorations.', 20, 'pcs', 5, 180.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(112, 8, 'DIY Souvenir Kit', 'EVT-009', 'Do-it-yourself souvenir kits for events and celebrations.', 15, 'set', 5, 350.00, 'active', NULL, '2026-04-08 01:43:40', '2026-04-08 01:43:40'),
(113, 5, 'Mugs', 'PROD-M-3', 'Imported from existing order history for Mugs', 0, 'piece', 0, 0.00, 'active', NULL, '2026-04-12 16:25:39', '2026-04-12 16:25:39'),
(114, 8, 'Invitations', 'PROD-I-9', 'Auto-created stock record for product monitoring.', 0, 'piece', 0, 0.00, 'active', NULL, '2026-04-12 16:25:39', '2026-04-12 16:25:39'),
(115, 6, 'Custom T-Shirts', 'PROD-C-T-S-10', 'Auto-created stock record for product monitoring.', 0, 'piece', 0, 0.00, 'active', NULL, '2026-04-12 16:25:39', '2026-04-12 16:25:39');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_transactions`
--

CREATE TABLE `inventory_transactions` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `type` enum('in','out','adjustment') NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventory_transactions`
--

INSERT INTO `inventory_transactions` (`id`, `inventory_id`, `type`, `quantity`, `reference`, `notes`, `created_by`, `created_at`) VALUES
(63, 81, 'out', 3.00, '', 'Stock out by staff', 7, '2026-04-08 01:57:34'),
(64, 104, 'out', 3.00, 'BA-20260411-4889', 'Sold via order BA-20260411-4889 (Birthday Package)', 7, '2026-04-11 04:07:56'),
(65, 109, 'in', 5.00, '', 'Stock in by staff', 7, '2026-04-11 07:32:20'),
(66, 85, 'out', 5.00, 'BA-20260412-8412', 'Auto stock deduction for order', 7, '2026-04-12 16:28:39'),
(67, 78, 'out', 5.00, 'BA-20260412-6262', 'Auto stock deduction for order', 8, '2026-04-12 16:35:16'),
(68, 79, 'out', 5.00, 'BA-20260413-5106', 'Auto stock deduction for order', 7, '2026-04-13 04:37:28'),
(69, 79, 'out', 5.00, 'BA-20260413-2209', 'Auto stock deduction for order', 7, '2026-04-13 04:37:36'),
(70, 81, 'out', 5.00, 'BA-20260413-6596', 'Auto stock deduction for order', 7, '2026-04-13 04:42:44'),
(71, 81, 'out', 1.00, 'BA-20260413-2820', 'Auto stock deduction for order', 7, '2026-04-13 05:14:31'),
(72, 81, 'out', 1.00, 'BA-20260413-4896', 'Auto stock deduction for order', 7, '2026-04-13 05:15:23'),
(73, 81, 'out', 1.00, 'BA-20260413-1598', 'Auto stock deduction for order', 7, '2026-04-13 05:15:58'),
(74, 81, 'out', 1.00, 'BA-20260413-4213', 'Auto stock deduction for order', 7, '2026-04-13 05:16:13'),
(75, 81, 'out', 1.00, 'BA-20260413-8816', 'Auto stock deduction for order', 7, '2026-04-13 05:16:16'),
(76, 81, 'out', 1.00, 'BA-20260413-8318', 'Auto stock deduction for order', 7, '2026-04-13 05:16:24'),
(77, 81, 'out', 5.00, 'BA-20260413-9373', 'Auto stock deduction for order', 7, '2026-04-13 05:30:15');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(30) NOT NULL,
  `customer_name` varchar(150) NOT NULL,
  `customer_contact` varchar(100) DEFAULT NULL,
  `source` enum('Facebook','Instagram','Walk-in','Referral','Other') DEFAULT 'Other',
  `order_type` varchar(100) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `deposit_paid` decimal(10,2) DEFAULT 0.00,
  `balance` decimal(10,2) DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
  `status` enum('pending','in_production','quality_check','ready','completed','cancelled','archived') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `product_image` varchar(255) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `order_number`, `customer_name`, `customer_contact`, `source`, `order_type`, `product_id`, `description`, `quantity`, `unit_price`, `total_amount`, `deposit_paid`, `balance`, `due_date`, `priority`, `status`, `notes`, `image`, `product_image`, `assigned_to`, `created_by`, `created_at`, `updated_at`) VALUES
(56, 'BA-20260413-9373', 'Chris Ian Sedon', '09463071088', 'Facebook', 'Business Cards', 2, '', 5, 123.00, 615.00, 0.00, 615.00, '2026-04-23', '', '', '', NULL, NULL, 7, 7, '2026-04-13 05:30:15', '2026-04-13 05:30:15');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `inventory_id` int(11) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `quantity_used` decimal(10,2) DEFAULT 0.00,
  `unit` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `inventory_id`, `item_name`, `quantity_used`, `unit`, `notes`) VALUES
(28, 56, 81, 'Business Cards', 5.00, 'piece', 'AUTO_PRODUCT_STOCK');

-- --------------------------------------------------------

--
-- Table structure for table `production_logs`
--

CREATE TABLE `production_logs` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `status_from` varchar(50) DEFAULT NULL,
  `status_to` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `production_logs`
--

INSERT INTO `production_logs` (`id`, `order_id`, `status_from`, `status_to`, `notes`, `updated_by`, `created_at`) VALUES
(114, 56, '', 'pending', 'Order created', 7, '2026-04-13 05:30:15');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `inventory_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(30) NOT NULL DEFAULT 'piece',
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `sort_order` int(11) NOT NULL DEFAULT 999,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `category_id`, `inventory_id`, `name`, `description`, `unit_price`, `unit`, `priority`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
(1, 8, 104, 'Birthday Package', 'Imported from existing order history for Birthday Package', 999.00, 'piece', 'normal', 100, 'active', '2026-04-12 14:47:35', '2026-04-12 16:25:39'),
(2, 5, 81, 'Business Cards', 'Imported from existing order history for Business Cards', 123.00, 'piece', 'normal', 10, 'active', '2026-04-12 14:47:35', '2026-04-12 16:25:39'),
(3, 5, 113, 'Mugs', 'Imported from existing order history for Mugs', 234.00, 'piece', 'normal', 90, 'active', '2026-04-12 14:47:35', '2026-04-12 16:25:39'),
(4, 5, 78, 'Flyers', '', 180.00, 'piece', 'normal', 20, 'active', '2026-04-12 15:34:35', '2026-04-12 16:25:39'),
(5, 5, 79, 'Brochures', '', 350.00, 'piece', 'normal', 30, 'active', '2026-04-12 15:34:35', '2026-04-12 16:25:39'),
(6, 5, 80, 'Posters', '', 120.00, 'piece', 'normal', 40, 'active', '2026-04-12 15:34:35', '2026-04-12 16:25:39'),
(7, 5, 84, 'Stickers', '', 85.00, 'piece', 'normal', 50, 'active', '2026-04-12 15:34:35', '2026-04-12 16:25:39'),
(8, 5, 85, 'Labels', '', 95.00, 'piece', 'normal', 60, 'active', '2026-04-12 15:34:35', '2026-04-12 16:25:39'),
(9, 8, 114, 'Invitations', '', 60.00, 'piece', 'normal', 70, 'active', '2026-04-12 15:34:35', '2026-04-12 16:25:39'),
(10, 6, 115, 'Custom T-Shirts', '', 450.00, 'piece', 'normal', 80, 'active', '2026-04-12 15:34:35', '2026-04-12 16:25:39');

-- --------------------------------------------------------

--
-- Table structure for table `staff_activity_log`
--

CREATE TABLE `staff_activity_log` (
  `id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','staff') NOT NULL DEFAULT 'staff',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `role`, `status`, `created_at`, `updated_at`) VALUES
(7, 'ian123', '$2y$10$bt2dAJRyq6Lu5Jv2I2BlvOeXD4/QqU1A6OpuchflMhKKpTQ2GE9Ma', 'Chris Ian Sedon', 'chrisian_sedon@sjp2cd.edu.ph', 'staff', 'active', '2026-03-25 14:19:48', '2026-03-25 15:21:08'),
(8, 'boss', '$2y$10$Dl30IvgDZXgJP402bmG1ZunbhYwOqAEBbR.gKhG.aE9ldS3026QOa', 'boss1', 'boss@gmail.com', 'admin', 'active', '2026-03-25 14:21:01', '2026-03-25 14:21:01');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `admin_code` (`admin_code`);

--
-- Indexes for table `admin_activity_log`
--
ALTER TABLE `admin_activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `inventory_id` (`inventory_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_ip` (`ip_address`),
  ADD KEY `idx_attempted` (`attempted_at`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `inventory_id` (`inventory_id`);

--
-- Indexes for table `production_logs`
--
ALTER TABLE `production_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_products_category` (`category_id`),
  ADD KEY `idx_products_inventory` (`inventory_id`);

--
-- Indexes for table `staff_activity_log`
--
ALTER TABLE `staff_activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_activity_log`
--
ALTER TABLE `admin_activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=116;

--
-- AUTO_INCREMENT for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=57;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `production_logs`
--
ALTER TABLE `production_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=115;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `staff_activity_log`
--
ALTER TABLE `staff_activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admins`
--
ALTER TABLE `admins`
  ADD CONSTRAINT `admins_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `admin_activity_log`
--
ALTER TABLE `admin_activity_log`
  ADD CONSTRAINT `admin_activity_log_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `inventory_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD CONSTRAINT `inventory_transactions_ibfk_1` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_transactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `production_logs`
--
ALTER TABLE `production_logs`
  ADD CONSTRAINT `production_logs_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `production_logs_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `staff_activity_log`
--
ALTER TABLE `staff_activity_log`
  ADD CONSTRAINT `staff_activity_log_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- GCash checkout payments (manual verification)
CREATE TABLE IF NOT EXISTS checkout_payments (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 reference_number VARCHAR(30) NOT NULL UNIQUE,
 payment_method VARCHAR(80) NOT NULL DEFAULT 'GCash',
 payment_method_other VARCHAR(100) NULL,
 proof_image VARCHAR(255) NOT NULL,
 amount DECIMAL(10,2) NOT NULL,
 status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
 review_note VARCHAR(500) NOT NULL DEFAULT '',
 reviewed_by INT NULL,
 reviewed_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS checkout_payment_orders (
 payment_id INT NOT NULL,
 order_id INT NOT NULL UNIQUE,
 amount DECIMAL(10,2) NOT NULL,
 PRIMARY KEY (payment_id, order_id),
 FOREIGN KEY (payment_id) REFERENCES checkout_payments(id),
 FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- PayMongo hosted GCash checkout
CREATE TABLE IF NOT EXISTS paymongo_checkouts (
 payment_id INT NOT NULL PRIMARY KEY,
 return_token CHAR(64) NOT NULL UNIQUE,
 session_id VARCHAR(100) NULL UNIQUE,
 checkout_url TEXT NULL,
 flow_type VARCHAR(20) NOT NULL DEFAULT 'hosted',
 payment_intent_id VARCHAR(100) NULL,
 client_key VARCHAR(255) NULL,
 payment_method_id VARCHAR(100) NULL,
 redirect_url TEXT NULL,
 state VARCHAR(30) NOT NULL DEFAULT 'new',
 livemode TINYINT(1) NOT NULL,
 paid_payment_id VARCHAR(100) NULL UNIQUE,
 last_checked_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_paymongo_payment_intent (payment_intent_id),
 FOREIGN KEY (payment_id) REFERENCES checkout_payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



-- Flexible pickup capacity per date
CREATE TABLE IF NOT EXISTS pickup_capacity (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 pickup_date DATE NOT NULL UNIQUE,
 max_orders INT NOT NULL DEFAULT 20,
 is_closed TINYINT(1) NOT NULL DEFAULT 0,
 note VARCHAR(255) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_pickup_capacity_date (pickup_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
