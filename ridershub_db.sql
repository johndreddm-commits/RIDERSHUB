-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 26, 2026 at 05:19 PM
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
-- Database: `ridershub_db`
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
(12, 26, 0, 5, '2026-01-26 12:47:18'),
(13, 27, 0, 5, '2026-01-26 12:52:42'),
(14, 28, 0, 5, '2026-01-26 12:55:56'),
(15, 29, 1, 5, '2026-01-26 15:11:14'),
(16, 30, 0, 5, '2026-01-26 13:01:11'),
(17, 31, 0, 5, '2026-01-26 13:10:01'),
(18, 32, 0, 5, '2026-01-26 13:11:59'),
(19, 33, 0, 5, '2026-01-26 13:13:50'),
(20, 34, 0, 5, '2026-01-26 13:26:31'),
(21, 35, 0, 5, '2026-01-26 13:28:08'),
(22, 36, 0, 5, '2026-01-26 13:29:48'),
(23, 37, 0, 5, '2026-01-26 13:31:27'),
(24, 38, 0, 5, '2026-01-26 13:33:11'),
(25, 39, 0, 5, '2026-01-26 13:34:17'),
(26, 40, 0, 5, '2026-01-26 13:35:46'),
(27, 41, 0, 5, '2026-01-26 13:37:11'),
(28, 42, 0, 5, '2026-01-26 14:49:54');

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
(6, 29, 'in', 1, '2026-01-26 15:11:14');

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
  `color` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `product_code`, `name`, `description`, `specs`, `price`, `stock`, `image`, `status`, `created_at`, `updated_at`, `brand`, `color`) VALUES
(26, 'DSEC-05492', 'HERO-SOLD MATTE GREY/TITANIUM', 'WITH FREE CLEAR VISION', '', 3300.00, 0, '1769431638_sec1-removebg-preview.png', 'active', '2026-01-26 12:47:18', '2026-01-26 12:47:18', 'sec', 'GREY'),
(27, 'DSEC-05430', 'SEC SPORTGRADE-FLASH ', 'WITH FREE COLOR VISOR AND CLEAN VISOR\r\n ', '', 3700.00, 0, '1769431962_sec2-removebg-preview.png', 'active', '2026-01-26 12:52:42', '2026-01-26 12:52:42', 'sec', 'TRIPLE GREY/WHITE'),
(28, 'DSEC-05436', 'SEC SPORTGRADE-FLASH ', 'WITH FREE COLOR VISOR AND CLEAN VISOR', '', 3700.00, 0, '1769432156_sec3-removebg-preview.png', 'active', '2026-01-26 12:55:56', '2026-01-26 12:55:56', 'sec', 'OPTIC WHITE/RED/SILVER'),
(29, 'DSEC-05581', 'ACE-GUARDIAN', 'WITH FREE CLEAR VISOR AND CLEAR LENS', '', 3300.00, 0, '1769432283_sec4-removebg-preview.png', 'active', '2026-01-26 12:58:03', '2026-01-26 12:58:03', 'sec', 'LIME GLOSS'),
(30, 'DSEC-05676', 'REVOLT 2023', 'WITH FREE VISOR & SMOKE SPOILER', '', 3400.00, 0, '1769432471_sec.png', 'active', '2026-01-26 13:01:11', '2026-01-26 13:01:11', 'sec', 'MATTE BLACK GREY'),
(31, 'DSEC-05553', 'ACE-SOLID', 'WITH FREE CLEAR VISOR', '', 3300.00, 0, '1769433001_SEC5-removebg-preview.png', 'active', '2026-01-26 13:10:01', '2026-01-26 13:10:01', 'sec', 'GLOSS WHITE'),
(32, 'DSEC-04545', 'TOURCH-ROOT', '', '', 3400.00, 0, '1769433119_SEC6-removebg-preview.png', 'active', '2026-01-26 13:11:59', '2026-01-26 13:11:59', 'sec', 'MATTE BLK/TITANUIM'),
(33, 'DSEC-05751', 'HORIZON-SOLID', 'WITH FREE CLEAR LENS & SMOKE SPOILER', '', 3600.00, 0, '1769433230_SEC7-removebg-preview.png', 'active', '2026-01-26 13:13:50', '2026-01-26 13:13:50', 'sec', 'MATTE BLACK/RED'),
(34, 'KYT-00001', 'KX-1 RACE GP', 'SPEED ADDICTS', '', 3500.00, 0, '1769433991_KYT1-removebg-preview.png', 'active', '2026-01-26 13:26:31', '2026-01-26 13:26:31', 'kyt', 'GLOOSY CARBON'),
(35, 'KYR-00002', 'KYT TT COURSE FUSELAGE ', 'MOTO-CANTRAL', '', 3200.00, 0, '1769434088_KYR2.webp', 'active', '2026-01-26 13:28:08', '2026-01-26 13:28:08', 'kyt', 'GLOSS RED'),
(36, 'KYT-00003', 'KYT - HELMET', '', '', 3300.00, 0, '1769434188_KYT3-removebg-preview.png', 'active', '2026-01-26 13:29:48', '2026-01-26 13:29:48', 'kyt', 'GREEN/WHITE'),
(37, 'KYT-00004', 'KYT CARBON FIBER ', 'HELMET RACING MOTORCYCLE HELMET FOR MEN AND WOMEN', '', 4500.00, 0, '1769434287_KYT4-removebg-preview.png', 'active', '2026-01-26 13:31:27', '2026-01-26 13:31:27', 'kyt', 'ORANGE'),
(38, 'KYT-00005', 'KYT NZ-RACE COMPITITION', '', '', 4200.00, 0, '1769434391_KYT5-removebg-preview.png', 'active', '2026-01-26 13:33:11', '2026-01-26 13:33:11', 'kyt', 'BLACK/WHITE'),
(39, 'KYT-00006', 'TT COURSE ARCHIVES', 'YG MOTO', '', 5000.00, 0, '1769434457_KYT6.png', 'active', '2026-01-26 13:34:17', '2026-01-26 13:34:17', 'kyt', 'ORAGE'),
(40, 'KYT-00007', 'KYT TT COURSE SPACE MONKEY', 'MOTO CENTRAL', '', 3400.00, 0, '1769434546_KYT7.webp', 'active', '2026-01-26 13:35:46', '2026-01-26 13:35:46', 'kyt', 'MONKEY GLOOS'),
(41, 'KYT-00008', 'KYT NZ RACE BLAZING ', '', '', 3500.00, 0, '1769434631_KYT8-removebg-preview.png', 'active', '2026-01-26 13:37:11', '2026-01-26 13:37:11', 'kyt', 'MATTE YELLOW'),
(42, 'EVO-00001', 'EVO HELMET GT PRO PR', 'DUAL VISOR', '', 4300.00, 0, '1769438994_EVO1-removebg-preview.png', 'active', '2026-01-26 14:49:54', '2026-01-26 14:49:54', 'evo', 'ULTRA LIGHT BLUE');

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
(15, 'ace rustia', 'ace@gmail.com', '$2y$10$YpVH/S8VhNCELmwQ6Q8cvOQvGSiwk3xSY7O9b0ZFpc67kQqt/BQNa', NULL, '2026-01-26 15:41:51', NULL, 1, 'user');

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
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_id` (`product_id`);

--
-- Indexes for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

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
  ADD PRIMARY KEY (`id`);

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
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  ADD CONSTRAINT `inventory_logs_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
