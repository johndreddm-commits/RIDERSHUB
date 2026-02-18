-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 03, 2026 at 08:00 AM
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
-- Database: `kbriders`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `admin_id` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `admin_id`, `password`) VALUES
(4, 'newadmin', '$2y$10$SIGHnwFlkDjIJr0qrF41xuzEPIKYw/pwBBuSyQHvRbfd.uBp5Yxru');

-- --------------------------------------------------------

--
-- Table structure for table `brands`
--

CREATE TABLE `brands` (
  `id` int(11) NOT NULL,
  `brand_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `brands`
--

INSERT INTO `brands` (`id`, `brand_name`) VALUES
(13, 'Evo'),
(15, 'Gille'),
(14, 'Kyt'),
(17, 'Spyder');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `min_stock` int(11) DEFAULT 5,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `product_id`, `quantity`, `min_stock`, `updated_at`) VALUES
(0, 54, 6, 5, '2026-01-31 09:46:50'),
(0, 55, 12, 5, '2026-01-31 09:47:02'),
(0, 56, 27, 5, '2026-01-31 05:38:58'),
(0, 57, 10, 5, '2026-01-31 10:19:55'),
(0, 58, 2, 5, '2026-01-31 12:23:15'),
(0, 45, 0, 5, '2026-01-31 09:33:52'),
(0, 46, 0, 5, '2026-01-31 09:33:52'),
(0, 47, 0, 5, '2026-01-31 09:33:52'),
(0, 48, 0, 5, '2026-01-31 09:33:52'),
(0, 49, 0, 5, '2026-01-31 09:33:52'),
(0, 51, 0, 5, '2026-01-31 09:33:52'),
(0, 52, 0, 5, '2026-01-31 09:33:52'),
(0, 53, 0, 5, '2026-01-31 09:33:52'),
(0, 50, 0, 5, '2026-01-31 09:33:52'),
(0, 59, 100, 5, '2026-01-31 12:24:58'),
(0, 60, 5, 5, '2026-01-31 11:53:29'),
(0, 61, 20, 5, '2026-01-31 12:33:03'),
(0, 62, 5, 5, '2026-02-02 05:35:02'),
(0, 63, 4, 5, '2026-02-02 05:55:42'),
(0, 64, 5, 5, '2026-02-02 05:58:35'),
(0, 65, 10, 5, '2026-02-02 06:41:11'),
(0, 66, 5, 5, '2026-02-03 06:21:23'),
(0, 67, 20, 5, '2026-02-03 06:10:07'),
(0, 68, 21, 5, '2026-02-03 06:19:57'),
(0, 69, 10, 5, '2026-02-02 07:07:33'),
(0, 70, 10, 5, '2026-02-02 07:10:05'),
(0, 71, 10, 5, '2026-02-02 07:11:26'),
(0, 72, 10, 5, '2026-02-02 07:13:09'),
(0, 73, 10, 5, '2026-02-02 07:15:03'),
(0, 74, 10, 5, '2026-02-02 07:16:35'),
(0, 75, 10, 5, '2026-02-02 07:19:39'),
(0, 76, 10, 5, '2026-02-02 07:22:08'),
(0, 77, 10, 5, '2026-02-02 07:43:34'),
(0, 78, 10, 5, '2026-02-02 07:48:02'),
(0, 79, 9, 5, '2026-02-02 08:33:21'),
(0, 80, 10, 5, '2026-02-02 08:15:50'),
(0, 81, 9, 5, '2026-02-02 09:18:46'),
(0, 82, 5, 5, '2026-02-03 06:33:33');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_logs`
--

CREATE TABLE `inventory_logs` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `action` enum('in','out','order') NOT NULL,
  `quantity` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_logs`
--

INSERT INTO `inventory_logs` (`id`, `product_id`, `action`, `quantity`, `created_at`) VALUES
(0, 54, 'out', 1, '2026-01-30 05:40:09'),
(0, 55, 'out', 1, '2026-01-30 05:47:42'),
(0, 55, 'out', 1, '2026-01-30 06:03:46'),
(0, 55, 'out', 5, '2026-01-30 06:04:38'),
(0, 54, 'out', 8, '2026-01-30 06:07:08'),
(0, 55, 'out', 14, '2026-01-31 05:30:57'),
(0, 57, 'out', 8, '2026-01-31 07:44:09'),
(0, 58, 'out', 3, '2026-01-31 09:25:05'),
(0, 58, 'out', 1, '2026-01-31 11:26:39'),
(0, 58, '', 10, '2026-01-31 11:27:46'),
(0, 58, 'out', 10, '2026-01-31 11:29:04'),
(0, 58, '', 6, '2026-01-31 11:29:33'),
(0, 58, 'out', 1, '2026-01-31 12:19:39'),
(0, 58, '', 11, '2026-01-31 12:22:01'),
(0, 58, 'out', 9, '2026-01-31 12:23:15'),
(0, 59, 'out', 9, '2026-01-31 12:24:15'),
(0, 59, '', 100, '2026-01-31 12:24:58'),
(0, 63, 'out', 1, '2026-02-02 05:55:42'),
(0, 79, 'out', 1, '2026-02-02 08:33:21'),
(0, 81, 'out', 1, '2026-02-02 09:18:46'),
(0, 67, '', 20, '2026-02-03 06:10:07'),
(0, 68, '', 21, '2026-02-03 06:19:57'),
(0, 66, '', 5, '2026-02-03 06:21:23'),
(0, 82, '', 5, '2026-02-03 06:40:11');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `quantity` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','delivered','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `product_code` varchar(50) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `specs` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) DEFAULT 0,
  `image` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `brand` varchar(50) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `brand_id` int(11) DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `helmet_type` varchar(50) NOT NULL,
  `quantity` int(11) DEFAULT 0,
  `colors` varchar(100) DEFAULT 'N/A',
  `sizes` varchar(100) DEFAULT 'N/A'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `product_code`, `name`, `description`, `specs`, `price`, `stock`, `image`, `status`, `created_at`, `updated_at`, `brand`, `color`, `brand_id`, `category_id`, `helmet_type`, `quantity`, `colors`, `sizes`) VALUES
(65, 'SR--20260202074111', 'SR-X-STARK', '', '', 3650.00, 10, '1770014471_evo 1 ff.jpg', 'active', '2026-02-02 06:41:11', '2026-02-02 08:01:27', NULL, NULL, 13, 0, 'full-face', 10, 'one color', 'l,xl'),
(66, 'SR--20260202075834', 'SR-09-TATAKAI', '', '', 4180.00, 5, '1770015514_evo 1 (2) ff.jpg', 'active', '2026-02-02 06:58:34', '2026-02-03 06:21:23', NULL, NULL, 13, 0, 'full-face', 5, '', 'L-Xl'),
(67, 'GT--20260202080218', 'GT-SPORT MONO COLORS', '', '', 3500.00, 20, '1770015738_evo 1 (3) ff.jpg', 'active', '2026-02-02 07:02:18', '2026-02-03 06:10:07', NULL, NULL, 13, 0, 'full-face', 20, '', 'L-XL'),
(68, 'GX--20260202080405', 'GX-1 COBALT', '', '', 3900.00, 21, '1770015845_evo 1(4) ff.jpg', 'active', '2026-02-02 07:04:05', '2026-02-03 06:19:57', NULL, NULL, 13, 0, 'full-face', 21, '', 'L-XL'),
(69, 'TR--20260202080733', 'TR-X RAVINE', '', '', 3100.00, 10, '1770016053_TR-X-RAVINE-PURPLE-PINK-2-300x300.jpg', 'active', '2026-02-02 07:07:33', '2026-02-02 07:07:33', NULL, NULL, 13, 0, 'half-face', 10, '', 'L-XL'),
(70, 'TR--20260202081005', 'TR-X ECHO', '', '', 3100.00, 10, '1770016205_TRX-ECHO.jpg', 'active', '2026-02-02 07:10:05', '2026-02-02 07:10:05', NULL, NULL, 13, 0, 'half-face', 10, '', 'L-XL'),
(71, 'TR--20260202081126', 'TR-X RADIX', '', '', 4100.00, 10, '1770016286_TRX RADIX.jpg', 'active', '2026-02-02 07:11:26', '2026-02-02 07:11:26', NULL, NULL, 13, 0, 'half-face', 10, '', 'L-XL'),
(72, 'TR--20260202081309', 'TR-X MONO COLORS', '', '', 2900.00, 10, '1770016389_TRX MONO.jpg', 'active', '2026-02-02 07:13:09', '2026-02-02 07:13:09', NULL, NULL, 13, 0, 'half-face', 10, '', 'L-XL'),
(73, 'VXR-20260202081503', 'VXR-8000 FRACTION', '', '', 4200.00, 10, '1770016503_Evo mod 1.jpg', 'active', '2026-02-02 07:15:03', '2026-02-02 07:15:03', NULL, NULL, 13, 0, 'modular', 10, '', 'L-XL'),
(74, 'TOU-20260202081635', 'TOURER MONO COLOR', '', '', 5800.00, 10, '1770016595_EVO MOD2.jpg', 'active', '2026-02-02 07:16:35', '2026-02-02 07:16:35', NULL, NULL, 13, 0, 'modular', 10, '', 'L-XL'),
(75, 'VXR-20260202081939', 'VXR-5000 TORSION', '', '', 4250.00, 10, '1770016779_EVO MOD3.jpg', 'active', '2026-02-02 07:19:39', '2026-02-02 07:19:39', NULL, NULL, 13, 0, 'modular', 10, '', 'L,XL'),
(76, 'VXR-20260202082208', 'VXR-8000 RISEN', '', '', 3830.00, 10, '1770016928_EVO MOD4.jpg', 'active', '2026-02-02 07:22:08', '2026-02-02 07:22:08', NULL, NULL, 13, 0, 'modular', 10, '', 'L-XL'),
(77, 'REC-20260202084333', 'Recon+ CF Plain', '', '', 10100.00, 10, '1770018213_spyder 1.webp', 'active', '2026-02-02 07:43:33', '2026-02-02 08:12:18', NULL, NULL, 17, 0, 'full-face', 10, 'black', 'l,xl'),
(78, 'SPI-20260202084802', 'Spike 2.0 Assault', '', '', 9900.00, 10, '1770018482_spyder2.webp', 'active', '2026-02-02 07:48:02', '2026-02-02 08:10:40', NULL, NULL, 17, 0, 'full-face', 10, 'black,white', 'l,xl'),
(79, 'REC-20260202091410', 'Recon+ CF Spectra', '', '', 9900.00, 9, '1770020050_spyder3.webp', 'active', '2026-02-02 08:14:10', '2026-02-02 08:33:21', NULL, NULL, 17, 0, 'full-face', 9, 'black,white', 'm,l,xl'),
(80, 'REC-20260202091550', 'Recon+ CF Plain Snake Carbon', '', '', 11000.00, 10, '1770020150_spyder4.webp', 'active', '2026-02-02 08:15:50', '2026-02-02 08:15:50', NULL, NULL, 17, 0, 'full-face', 10, 'black', 'l,xl'),
(81, 'ROV-20260202091814', 'Rover Plain V2', '', '', 4100.00, 9, '1770020294_spydermod1.webp', 'active', '2026-02-02 08:18:14', '2026-02-02 09:18:46', NULL, NULL, 17, 0, 'full-face', 9, 'white,black', 'l,xl');

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `customer_name` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `pickup_date` date NOT NULL,
  `ticket_number` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT 'PENDING',
  `quantity` int(11) DEFAULT 1,
  `selected_color` varchar(50) DEFAULT NULL,
  `selected_size` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reservations`
--

INSERT INTO `reservations` (`id`, `product_id`, `customer_name`, `phone`, `pickup_date`, `ticket_number`, `created_at`, `status`, `quantity`, `selected_color`, `selected_size`) VALUES
(1, 41, 'james', '09550878574', '2026-06-16', 'KB-816A', '2026-01-28 08:56:31', 'CONFIRMED', 1, NULL, NULL),
(2, 32, 'james gabo', '090062818', '2026-12-06', 'KB-4E4D', '2026-01-28 09:28:38', 'CONFIRMED', 1, NULL, NULL),
(3, 42, 'juan dela cruz', '870000000000', '2026-06-18', 'KB-9219', '2026-01-28 09:39:36', 'PENDING', 1, NULL, NULL),
(4, 45, 'gabo', '09550878574', '2026-01-29', 'KB-9708', '2026-01-28 11:02:07', 'CONFIRMED', 1, NULL, NULL),
(5, 48, 'james gabriel gapas', '09550878574', '2026-01-13', 'KB-90FF', '2026-01-28 13:15:07', 'CONFIRMED', 1, NULL, NULL),
(6, 48, 'james gabriel gapas', '09550878574', '2026-12-06', 'KB-4B21', '2026-01-28 13:19:21', 'CONFIRMED', 1, NULL, NULL),
(7, 48, 'james gabriel gapas', '09550878574', '2026-01-29', 'KB-4EC3', '2026-01-28 14:12:39', 'CONFIRMED', 1, NULL, NULL),
(8, 48, 'juan dela cruz', '09550878574', '2026-02-23', 'KB-FF81', '2026-01-28 14:42:19', 'PENDING', 1, NULL, NULL),
(9, 48, 'rustia', '870000000000', '2026-01-31', 'KB-FB8D', '2026-01-29 06:15:18', 'PENDING', 1, NULL, NULL),
(10, 49, 'juan dick a cruz', '09550878574', '2026-01-08', 'KB-F2D6', '2026-01-29 06:22:09', 'PENDING', 1, NULL, NULL),
(11, 49, 'johndrew', '09550878574', '2026-06-10', 'KB-211E', '2026-01-29 11:24:02', 'CONFIRMED', 1, NULL, NULL),
(12, 49, 'james', '09550878574', '2026-01-22', 'KB-AACA', '2026-01-30 05:05:49', 'PENDING', 1, NULL, NULL),
(13, 54, 'gabo', '09550878574', '2026-01-31', 'KB-3547', '2026-01-30 05:31:44', 'CONFIRMED', 1, NULL, NULL),
(14, 54, 'joseph', '09550878574', '2026-06-18', 'KB-2D1D', '2026-01-30 05:39:28', 'CONFIRMED', 1, NULL, NULL),
(15, 55, 'james gabriel gapas', '09550878574', '2026-02-10', 'KB-50F7', '2026-01-30 05:47:25', 'CONFIRMED', 1, NULL, NULL),
(16, 55, 'gabo', '09550878574', '2026-06-18', 'KB-D899', '2026-01-30 05:49:00', 'CONFIRMED', 1, NULL, NULL),
(17, 55, 'gabo', '09550878574', '2026-06-18', 'KB-4DFB', '2026-01-30 06:04:18', 'CONFIRMED', 5, NULL, NULL),
(18, 54, 'james', '09550878574', '2026-02-26', 'KB-668A', '2026-01-30 06:06:52', 'CONFIRMED', 8, NULL, NULL),
(19, 55, 'joseph rustia', '086662881781', '2026-02-03', 'KB-6668', '2026-01-31 05:30:31', 'CONFIRMED', 14, NULL, NULL),
(20, 57, 'nepo', '09550878574', '2026-02-23', 'KB-849F', '2026-01-31 07:43:34', 'CONFIRMED', 8, NULL, NULL),
(21, 57, 'nepo', '09550878574', '2026-02-23', 'KB-0DAA', '2026-01-31 07:43:34', 'PENDING', 8, NULL, NULL),
(22, 58, 'riri ', '09550878574', '2026-02-04', 'KB-7290', '2026-01-31 08:41:38', 'PENDING', 3, NULL, NULL),
(23, 58, 'johndrwq', '09550878574', '2026-02-03', 'KB-7447', '2026-01-31 09:24:33', 'CONFIRMED', 3, NULL, NULL),
(24, 58, 'james gabriel gapas', '09550878574', '2028-06-18', 'KB-0F36', '2026-01-31 11:26:18', 'CONFIRMED', 1, NULL, NULL),
(25, 58, 'justin nepo', '870000000000', '2026-06-18', 'KB-8DFC', '2026-01-31 11:28:37', 'CONFIRMED', 10, NULL, NULL),
(26, 58, 'james', '859870770', '2026-06-22', 'KB-3789', '2026-01-31 12:19:01', 'CONFIRMED', 1, NULL, NULL),
(27, 58, 'ggap', '0955087857', '2026-02-22', 'KB-AA62', '2026-01-31 12:22:52', 'CONFIRMED', 9, NULL, NULL),
(28, 59, 'gabo', '09550878574', '2026-02-20', 'KB-1846', '2026-01-31 12:24:00', 'CONFIRMED', 9, NULL, NULL),
(29, 63, 'james gabriel gapas', '09550878574', '2026-06-18', 'KB-FC35', '2026-02-02 05:55:18', 'CONFIRMED', 1, NULL, NULL),
(30, 65, 'james gabriel gapas', '09550878574', '2026-03-18', 'KB-9094', '2026-02-02 08:03:05', 'CANCELLED', 1, NULL, NULL),
(31, 81, 'james gabriel gapas', '09550878574', '2026-03-26', 'KB-C1C7', '2026-02-02 08:27:56', 'PENDING', 1, NULL, NULL),
(32, 80, 'gabo', '09550878574', '2026-03-31', 'KB-9647', '2026-02-02 08:29:54', 'PENDING', 1, NULL, NULL),
(33, 80, 'gabo', '09550878574', '2026-03-31', 'KB-0660', '2026-02-02 08:29:55', 'CANCELLED', 1, NULL, NULL),
(34, 80, 'gabo', '09550878574', '2026-03-31', 'KB-0AA9', '2026-02-02 08:29:56', 'CANCELLED', 1, NULL, NULL),
(35, 80, 'gabo', '09550878574', '2026-03-31', 'KB-155E', '2026-02-02 08:29:58', 'CANCELLED', 1, NULL, NULL),
(36, 80, 'gabo', '09550878574', '2026-03-31', 'KB-F383', '2026-02-02 08:30:02', 'CANCELLED', 1, NULL, NULL),
(37, 80, 'gabo', '09550878574', '2026-03-31', 'KB-9376', '2026-02-02 08:30:02', 'CANCELLED', 1, NULL, NULL),
(38, 80, 'gabo', '09550878574', '2026-03-31', 'KB-CB58', '2026-02-02 08:30:03', 'CANCELLED', 1, NULL, NULL),
(39, 80, 'gabo', '09550878574', '2026-03-31', 'KB-7546', '2026-02-02 08:30:03', 'CANCELLED', 1, NULL, NULL),
(40, 80, 'gabo', '09550878574', '2026-03-31', 'KB-B863', '2026-02-02 08:30:03', 'CANCELLED', 1, NULL, NULL),
(41, 80, 'gabo', '09550878574', '2026-03-31', 'KB-7B81', '2026-02-02 08:30:05', 'CANCELLED', 1, NULL, NULL),
(42, 80, 'gabo', '09550878574', '2026-03-31', 'KB-64AA', '2026-02-02 08:30:05', 'CANCELLED', 1, NULL, NULL),
(43, 80, 'gabo', '09550878574', '2026-03-31', 'KB-4154', '2026-02-02 08:30:06', 'CANCELLED', 1, NULL, NULL),
(44, 80, 'gabo', '09550878574', '2026-03-31', 'KB-E8F9', '2026-02-02 08:30:06', 'CANCELLED', 1, NULL, NULL),
(45, 80, 'gabo', '09550878574', '2026-03-31', 'KB-BCDB', '2026-02-02 08:30:16', 'CANCELLED', 1, NULL, NULL),
(46, 79, 'gabo', '09550878574', '2026-03-22', 'KB-E9D8', '2026-02-02 08:32:42', 'CONFIRMED', 1, 'black', 'm'),
(47, 79, 'james gabriel gapas', '09550878574', '2026-03-08', 'KB-CA0D', '2026-02-02 08:34:43', 'PENDING', 1, 'black', 'xl'),
(48, 81, 'james', '5455677788', '2026-03-31', 'KB-6A62', '2026-02-02 09:17:39', 'CONFIRMED', 1, 'white', 'xl');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `role` enum('admin','user') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `fullname`, `email`, `password`, `phone`, `created_at`, `last_login`, `is_active`, `role`) VALUES
(9, 'monay lang', 'monay@gmail.com', '$2y$10$zrK/dA1rBbGFqWHEfm/In.4qyjHDPvbf.RBR0lQLGL3i0cdZAfySK', NULL, '2026-01-21 20:37:55', NULL, 1, 'user'),
(10, 'admin', 'admin@gmail.com', '$2y$10$h0B3p4AnxaG5YPjJzumdFOwua.g8rAmKHSADJtWlA5wtom5itThT2', NULL, '2026-01-21 20:56:45', NULL, 1, 'user'),
(11, 'kupal kaba?', 'kupal@gmail.com', '$2y$10$.gSmBYxcX6BbW8MWrBx3ReErS.2k8Cv5RRyjNBkM6YI8F0l1h2QPa', NULL, '2026-01-22 14:08:51', NULL, 1, 'user'),
(12, 'rustia joseph', 'joseph@gmail.com', '$2y$10$WbqBPP.HtL63grxCezP30epEmEG.AGDuEIpizlXh5ljEjaJX/.NC2', NULL, '2026-01-22 15:41:02', NULL, 1, 'user'),
(13, 'asd3wa', 'ad3wa@gmail.com', '$2y$10$eCF5f6.EY4iiibgmKHEKWOoHxpOx6be8uQ7AjAedBqu4HIbmKbU0S', NULL, '2026-01-23 14:07:08', NULL, 1, 'user'),
(14, 'ako', 'ako@gmail.com', '$2y$10$BRxGzPkU4VITIlrV8LDx5.WyIvEWo0RcGoAmEJ6eDbxXJgTyYPpHW', NULL, '2026-01-23 16:40:23', NULL, 1, 'user'),
(15, 'ace rustia', 'ace@gmail.com', '$2y$10$YpVH/S8VhNCELmwQ6Q8cvOQvGSiwk3xSY7O9b0ZFpc67kQqt/BQNa', NULL, '2026-01-26 15:41:51', NULL, 1, 'user'),
(16, 'james', 'gabo@gmail.com', '$2y$10$pAMmZ67aWfA4AsFGqFYAw.O11EUYmIW/0qMt/Pk83Bkn7sp3U2MXe', NULL, '2026-01-28 06:24:44', NULL, 1, 'user');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `admin_id` (`admin_id`);

--
-- Indexes for table `brands`
--
ALTER TABLE `brands`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `brand_name` (`brand_name`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_orders_product` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_product_brand` (`brand_id`),
  ADD KEY `fk_brand_name` (`brand`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_number` (`ticket_number`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `brands`
--
ALTER TABLE `brands`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_brand_name` FOREIGN KEY (`brand`) REFERENCES `brands` (`brand_name`),
  ADD CONSTRAINT `fk_product_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
