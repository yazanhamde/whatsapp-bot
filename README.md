# 🤖 WhatsApp AI Sales & Support Bot

> A production-ready WhatsApp Business AI agent with RAG knowledge base, inventory management, and a full web dashboard — built Arabic first.

Built for a real appliance repair & retail business. Handles hundreds of customer queries daily, manages orders and inventory, and escalates to human agents when needed.

---

## ✨ Features

- **RAG Knowledge Base** — Answers product and service questions from a curated knowledge base, not hallucinations
- **Order Management** — Customers can place, track and manage orders directly via WhatsApp
- **Inventory System** — Real-time product availability and stock tracking
- **Image Analysis** — Reads product photos and repair request images via GPT-4o Vision
- **Voice Message Support** — Transcribes Arabic voice messages via Whisper
- **Human Takeover** — Owner can pause the bot per-conversation or globally in one tap
- **Monthly Reports** — Auto-generated Arabic reports with conversation stats and drop-off analysis
- **Unresolved Images Dashboard** — Review and act on images the bot couldn't handle
- **Product Sync** — Sync products from external sources into the bot's knowledge
- **Owner Alerts** — Instant notifications for critical customer events
- **Role-Based Dashboard** — Secure web panel for managing conversations, employees, and bot instructions
- **PWA Support** — Dashboard works as an installable mobile app

---

## 🛠️ Tech Stack

| Layer | Tech |
|---|---|
| Backend | PHP 8.x |
| AI | OpenAI GPT-4o-mini, Whisper, Vision, Embeddings |
| Channel | WhatsApp Cloud API |
| RAG | OpenAI Embeddings + cosine similarity |
| Database | MySQL |
| Frontend | Vanilla JS + PWA |
| Auth | JWT + role-based access |

---

## 📁 Project Structure

```
├── webhook.php                  # WhatsApp webhook handler
├── index.php                    # Dashboard entry point
├── config/
│   ├── settings.php             # Environment loader & constants
│   ├── db.php                   # Database connection
│   ├── api_helpers.php          # OpenAI & WhatsApp API wrappers
│   └── auth_helpers.php         # JWT auth helpers
├── api/
│   ├── rag.php                  # RAG knowledge base management
│   ├── knowledge-base.php       # Knowledge base CRUD
│   ├── orders.php               # Order management
│   ├── inventory.php            # Inventory management
│   ├── products.php             # Product catalog
│   ├── sync_products.php        # Product sync from external source
│   ├── conversations.php        # Conversation management
│   ├── messages.php             # Message history
│   ├── reply.php                # Manual reply from dashboard
│   ├── employees.php            # Employee management
│   ├── toggle_bot.php           # Toggle bot per conversation
│   ├── toggle_global_bot.php    # Global bot on/off
│   ├── bot_instructions.php     # Update system prompt live
│   ├── monthly_report.php       # Monthly Arabic report
│   ├── dropoff_report.php       # Drop-off analysis
│   ├── owner_alerts.php         # Owner notification system
│   └── unresolved_images_api.php# Unresolved image review
└── products-dashboard/          # PWA web dashboard
```

---

## ⚙️ Setup

### 1. Clone & configure

```bash
git clone https://github.com/yazanhamde/whatsapp-bot
cd whatsapp-bot
cp .env.example .env
```

### 2. Fill in `.env`

```env
# OpenAI
OPENAI_KEY=your_openai_key_here
OPENAI_MODEL=gpt-4o-mini
OPENAI_VISION_MODEL=gpt-4o
OPENAI_WHISPER=whisper-1

# WhatsApp Cloud API
WA_PHONE_ID=your_phone_number_id
WA_TOKEN=your_whatsapp_token
WA_APP_SECRET=your_app_secret
WA_VERIFY_TOKEN=your_verify_token

# Database
DB_HOST=localhost
DB_NAME=your_db_name
DB_USER=your_db_user
DB_PASS=your_db_password

# Owner
OWNER_PHONE=962XXXXXXXXX
OWNER_NAME=Your Name
STORE_NAME=Your Store Name
```

### 3. Import database

```bash
mysql -u your_user -p your_db < schema.sql
```

### 4. Register webhook on Meta

```
https://yourdomain.com/webhook.php
```

Subscribe to: **messages**

---

## 🔐 Security

- WhatsApp webhook signature verified on every request
- JWT authentication on all dashboard API endpoints
- Role-based access control (owner / employee)
- No sensitive data in code — `.env` only

---

## 📊 Production Stats

- Handles **hundreds of customer queries daily**
- Supports **Arabic voice messages** and **product images**
- Owner gets **monthly Arabic reports** automatically
- Human agents can **take over any conversation** in one click

---

## 👤 Author

**Yazan Hamdi** — Full Stack AI Engineer  
[github.com/yazanhamde](https://github.com/yazanhamde) · [goldensketch.com/yazan-hamdi-demo](https://goldensketch.com/yazan-hamdi-demo/)
