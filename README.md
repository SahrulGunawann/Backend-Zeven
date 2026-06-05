# Zeven Marketplace - Backend API

![Zeven Logo](https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg)

Backend API untuk platform **Zeven Social E-Commerce Marketplace**. Dibangun menggunakan Laravel 10 dengan arsitektur RESTful API untuk melayani aplikasi Mobile (Ionic) dan Dashboard Web (Admin & Seller).

## 🚀 Fitur Utama
- **Multi-Role System**: Admin, Seller, dan Buyer.
- **Payment Integration**: Integrasi pembayaran menggunakan DompetX.
- **Real-time Chat**: Sistem pesan instan antar user.
- **Push Notifications**: Terintegrasi dengan Firebase Cloud Messaging (FCM).
- **Premium Dashboard**: UI Dashboard Admin & Seller yang modern dengan custom dropdown dan filter.
- **Unified Database**: Migrasi database yang telah disederhanakan untuk kemudahan deployment.

## 🛠 Tech Stack
- **Framework**: Laravel 10
- **Database**: MySQL
- **Authentication**: Laravel Sanctum
- **Notification**: Firebase (FCM)
- **Payment**: DompetX API

## 📦 Instalasi Lokal

1. Clone repository:
   ```bash
   git clone https://github.com/SahrulGunawann/Backend-Zeven.git
   ```
2. Install dependencies:
   ```bash
   composer install
   ```
3. Copy file `.env`:
   ```bash
   cp .env.example .env
   ```
4. Generate App Key:
   ```bash
   php artisan key:generate
   ```
5. Atur koneksi database di `.env`, lalu jalankan migrasi & seeder:
   ```bash
   php artisan migrate --seed
   ```
6. Jalankan server:
   ```bash
   php artisan serve
   ```
---
Developed with by **ZevenDev**
