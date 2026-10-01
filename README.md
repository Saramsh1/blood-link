# Blood Link — AI-Powered Blood Bank Management System
**Final Year Project | Saramsh Puri | LCID: LC00017002058**
Lincoln University College, Malaysia | December 2025

---

## Project Overview

Blood Link is a web-based blood bank management system that uses machine learning to:
- **Screen donor eligibility** using age, weight, hemoglobin, medical history
- **Match donors to hospital requests** using blood type compatibility + geolocation + availability scoring
- **Automate notifications** via Email (SMTP) and SMS (Twilio)

---

## Tech Stack

| Layer         | Technology                  |
|---------------|-----------------------------|
| Frontend      | HTML5 / CSS3 / JavaScript   |
| Backend       | PHP 8.1 + Laravel 10        |
| Database      | MySQL 8.0                   |
| AI/ML         | Python 3.10 + scikit-learn  |
| Auth          | JWT (tymon/jwt-auth)        |
| Notifications | Twilio (SMS) + SMTP (Email) |
| Dev Server    | XAMPP / WAMP                |

---

## Project Structure

```
bloodlink/
├── frontend/
│   ├── index.html                  ← Landing page
│   └── pages/
│       ├── login.html              ← Login (donor/hospital/admin)
│       ├── register.html           ← Donor registration + AI preview
│       ├── donor-dashboard.html    ← Donor portal
│       ├── hospital-dashboard.html ← Hospital portal
│       └── admin-dashboard.html    ← Admin panel
│
├── backend/
│   └── BloodLinkBackend.php        ← All PHP controllers & services
│                                     (drop into Laravel project)
│
├── ml/
│   └── ml_api.py                   ← Python AI/ML REST API
│
└── database/
    └── schema.sql                  ← Full MySQL schema + seed data
```

---

## Setup Guide

### Step 1 — Database
```bash
# Start MySQL in XAMPP
# Open phpMyAdmin or run:
mysql -u root -p < database/schema.sql
```

### Step 2 — Laravel Backend
```bash
# Install Laravel
composer create-project laravel/laravel bloodlink-api
cd bloodlink-api

# Install JWT
composer require tymon/jwt-auth
php artisan vendor:publish --provider="Tymon\JWTAuth\Providers\LaravelServiceProvider"
php artisan jwt:secret

# Copy backend code into app/
# Copy routes into routes/api.php

# Configure .env
DB_DATABASE=bloodlink
DB_USERNAME=root
DB_PASSWORD=

TWILIO_SID=your_sid
TWILIO_TOKEN=your_token
TWILIO_FROM=+1234567890
ML_API_URL=http://localhost:5000

# Start server
php artisan serve --port=8000
```

### Step 3 — Python ML API
```bash
cd ml/
pip install flask flask-cors

# Run the API server
python ml_api.py
# → Running on http://0.0.0.0:5000

# Test it
python ml_api.py --demo
```

### Step 4 — Frontend
Open `frontend/index.html` in a browser, or serve via XAMPP's `htdocs/`.

---

## Demo Accounts

| Role     | Email               | Password  |
|----------|---------------------|-----------|
| Donor    | donor@demo.com      | pass123   |
| Hospital | hospital@demo.com   | pass123   |
| Admin    | admin@demo.com      | admin123  |

---

## AI/ML Module

### Eligibility Screening (`POST /api/screen`)
```json
{
  "age": 24,
  "weight": 68,
  "hemoglobin": 14.2,
  "days_since_last_donation": 90,
  "medical_conditions": ["none"],
  "gender": "male",
  "total_donations": 7
}
```
**Response:**
```json
{
  "eligible": true,
  "confidence": 0.99,
  "score": 100.0,
  "reasons": [],
  "warnings": []
}
```

### Donor Matching (`POST /api/match`)
```json
{
  "request": {
    "blood_type": "A+",
    "units_needed": 2,
    "urgency": "critical",
    "hospital_lat": 3.1478,
    "hospital_lng": 101.6953
  },
  "donors": [ ... ]
}
```
**Response:** Sorted list of donors with `match_score` and `distance_km`.

---

## Scoring Algorithm

| Factor                        | Weight |
|-------------------------------|--------|
| Blood type exact match        | 40 pts |
| Blood type compatible match   | 25 pts |
| Distance (inverse, max 50 km) | 25 pts |
| Recency (days since donation) | 10 pts |
| Donation frequency            |  5 pts |

---

## API Endpoints

```
POST /api/auth/register           Register donor or hospital
POST /api/auth/login              Login → JWT token
GET  /api/auth/me                 Current user

GET  /api/donor/profile           Donor profile + eligibility
PUT  /api/donor/health            Update health + AI re-screen
GET  /api/donor/donations         Donation history
GET  /api/donor/requests/nearby   Blood requests near donor

POST /api/hospital/requests       Create request + run AI match
GET  /api/hospital/requests/{id}/matches  View matched donors
POST /api/hospital/requests/{id}/notify   Notify donors
GET  /api/hospital/inventory      Blood inventory

GET  /api/admin/stats             System overview
GET  /api/admin/donors            All donors
GET  /api/admin/reports/monthly   Monthly report
```

---

## Supervisor
**Er. Rohit Pandey** | Internal Supervisor

© 2025 Blood Link — Lincoln University College, Malaysia
