<div align="center">

# 🤖 WhatsApp AI Agent

**بوت واتساب ذكي لإدارة طلبات الصيانة وبيع قطع الغيار**  
*Intelligent WhatsApp bot for managing maintenance orders & spare parts sales*

[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](https://php.net)
[![WhatsApp](https://img.shields.io/badge/WhatsApp-Cloud_API-25D366?logo=whatsapp&logoColor=white)](https://developers.facebook.com/docs/whatsapp)
[![OpenAI](https://img.shields.io/badge/OpenAI-GPT--4o-412991?logo=openai&logoColor=white)](https://openai.com)
[![MariaDB](https://img.shields.io/badge/MariaDB-11.4-003545?logo=mariadb&logoColor=white)](https://mariadb.org)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![LinkedIn](https://img.shields.io/badge/LinkedIn-Yazan%20Hamdi-blue?logo=linkedin)](https://www.linkedin.com/in/yazan-hamdi/)
</div>

---

## 📋 نظرة عامة | Overview

نظام متكامل يربط WhatsApp Cloud API بنماذج OpenAI لتشغيل وكيل محادثة ذكي يقدر يستقبل طلبات الصيانة، يبحث عن قطع الغيار بالنص أو الصورة، ويدير المحادثات مع الزباين بشكل شبه أوتوماتيكي — مع لوحة تحكم ويب كاملة للمالك.

> A full-stack conversational AI system that handles home appliance maintenance orders and spare parts inquiries over WhatsApp, powered by GPT-4o with RAG memory, vision-based product search, and Arabic voice transcription.

---

## ✨ المميزات الرئيسية | Features

### 🧠 الذكاء الاصطناعي
- **محادثة طبيعية** بالعربية (فصحى + عامية) مع شخصية مخصصة قابلة للتعديل
- **RAG Memory** — البوت يتعلم من كل محادثة ويتذكر الإجابات الصحيحة
- **Vision Search** — البحث عن قطع الغيار بالصورة عبر GPT-4o Vision + Embeddings
- **تفريغ الصوت** — تحويل الرسائل الصوتية لنص عبر Whisper API

### 📦 إدارة الطلبات
- استقبال وتثبيت طلبات الصيانة أوتوماتيكياً
- تتبع حالة الطلبات (مفتوح / قيد التنفيذ / مكتمل)
- تقارير شهرية وتقرير `drop-off` للطلبات المتروكة
- إشعارات فورية للمالك عند الطلبات المهمة

### 🏪 لوحة التحكم (PWA)
- **Dashboard ويب** كامل يشتغل على الموبايل والديسكتوب
- إدارة المحادثات والرد المباشر على الزباين
- إدارة المنتجات وقطع الغيار (مزامنة مع POS خارجي)
- قاعدة المعرفة (Knowledge Base) وذاكرة RAG
- إعدادات البوت وتعليماته
- تحكم كامل في تشغيل/إيقاف البوت

### 🔒 الأمان
- التحقق من HMAC-SHA256 لكل رسائل WhatsApp
- API مؤمّن بـ token-based authentication
- منع SQL Injection بـ prepared statements
- تنظيف تلقائي للميديا القديمة

---

## 🏗️ هيكل المشروع | Project Structure

```
whatsapp-bot/
│
├── webhook.php              # نقطة دخول WhatsApp Webhook
├── index.php                # Health check endpoint
├── health.php               # فحص تفصيلي للمكونات
│
├── config/
│   ├── settings.php         # تحميل .env والإعدادات
│   ├── db.php               # اتصال PDO بقاعدة البيانات
│   ├── auth_helpers.php     # مساعدات المصادقة
│   └── api_helpers.php      # مساعدات الـ API
│
├── includes/
│   ├── Agent.php            # الموجّه الرئيسي (Router)
│   ├── WhatsApp.php         # WhatsApp Cloud API client
│   ├── OpenAI.php           # OpenAI API client
│   ├── RAG.php              # Retrieval-Augmented Generation
│   ├── KnowledgeBase.php    # قاعدة المعرفة
│   ├── ProductSearch.php    # البحث النصي والبصري للمنتجات
│   │
│   ├── traits/
│   │   ├── ConversationTrait.php      # إدارة المحادثات
│   │   ├── MediaHandlerTrait.php      # معالجة الصور والصوت
│   │   ├── OwnerNotifyTrait.php       # إشعارات المالك
│   │   ├── OwnerCommandsTrait.php     # أوامر المالك عبر واتساب
│   │   └── MaintenanceFlowTrait.php   # مسار طلبات الصيانة
│   │
│   └── bots/
│       ├── MohammadBotTrait.php       # بوت الزباين الرئيسي
│       └── AbdullahBotTrait.php       # بوت مساعد إضافي
│
├── api/                     # REST API endpoints
│   ├── auth/
│   │   ├── login.php
│   │   └── me.php
│   ├── conversations.php
│   ├── messages.php
│   ├── orders.php
│   ├── products.php
│   ├── knowledge-base.php
│   ├── bot_instructions.php
│   ├── rag.php
│   ├── sync_products.php
│   ├── monthly_report.php
│   ├── dropoff_report.php
│   └── ...
│
├── products-dashboard/      # لوحة التحكم (PWA)
│   ├── index.html
│   ├── app.js
│   └── sections/
│       ├── conversations.js
│       ├── orders.js
│       ├── products.js
│       ├── knowledge.js
│       ├── rag.js
│       └── ...
│
├── uploads/                 # ميديا مؤقتة (لا تُرفع لـ Git)
├── vendor/                  # Composer dependencies (لا تُرفع لـ Git)
├── .env                     # متغيرات البيئة (لا تُرفع لـ Git)
├── .env.example             # قالب الإعدادات
└── .gitignore
```

---

## 🚀 التثبيت | Installation

### المتطلبات
- PHP 8.2+
- MariaDB / MySQL 10.6+
- Composer
- SSL certificate (مطلوب لـ WhatsApp Webhook)
- حساب WhatsApp Business API (Meta)
- مفتاح OpenAI API

### الخطوات

**1. استنسخ المشروع**
```bash
git clone https://github.com/yazanhamde/whatsapp-bot.git
cd whatsapp-bot/whatsapp-bot
```

**2. ثبّت الاعتماديات**
```bash
composer install
```

**3. اضبط الإعدادات**
```bash
cp .env.example .env
nano .env
```

**4. أنشئ قاعدة البيانات**
```bash
mysql -u root -p your_database < database/wa-agent_schema.sql
```

**5. اضبط الـ Webhook على Meta Dashboard**
```
Webhook URL:  https://yourdomain.com/webhook.php
Verify Token: (نفس WA_VERIFY_TOKEN في .env)
```

**6. تحقق من التثبيت**
```
GET https://yourdomain.com/
```

---

## ⚙️ متغيرات البيئة | Environment Variables

انسخ `.env.example` إلى `.env` واملأ القيم:

```env
# OpenAI
OPENAI_KEY=sk-...
OPENAI_MODEL=gpt-4o-mini
OPENAI_VISION_MODEL=gpt-4o

# WhatsApp Cloud API
WA_PHONE_ID=your_phone_id
WA_TOKEN=your_token
WA_APP_SECRET=your_app_secret
WA_VERIFY_TOKEN=your_verify_token
WA_BOT_PHONE=your_bot_number

# Database
DB_HOST=localhost
DB_NAME=your_db
DB_USER=your_user
DB_PASS=your_password

# Owner
OWNER_PHONE=your_phone
STORE_NAME=Your Store
OWNER_API_KEY=generate_strong_random_key
```

---

## 🔄 كيف يعمل | How It Works

```
زبون يرسل رسالة
        ↓
  webhook.php
        ↓
  Agent::handle()
        ↓
   ┌────┴────┐
   │         │
رسالة      رسالة
المالك    الزبون
   │         │
   ↓         ↓
OwnerCmds  MohammadBot
           │
    ┌──────┼──────┐
    │      │      │
   RAG  KnowledgeBase  ProductSearch
    │      │      │
    └──────┼──────┘
           ↓
       GPT-4o
           ↓
     WhatsApp Reply
```

---

## 📊 قاعدة البيانات | Database

الجداول الرئيسية:

| الجدول | الوصف |
|--------|-------|
| `customers` | بيانات الزباين |
| `conversations` | المحادثات النشطة والمؤرشفة |
| `messages` | سجل كامل للرسائل |
| `service_requests` | طلبات الصيانة |
| `products` | قاعدة المنتجات وقطع الغيار |
| `product_visual_index` | Embeddings للبحث البصري |
| `rag_memories` | ذاكرة RAG |
| `knowledge_base` | قاعدة المعرفة |
| `settings` | إعدادات البوت وتعليماته |

---

## 🛠️ التقنيات المستخدمة | Tech Stack

| التقنية | الاستخدام |
|---------|-----------|
| PHP 8.4 | Backend |
| MariaDB 11.4 | Database |
| WhatsApp Cloud API | Messaging |
| GPT-4o / GPT-4o-mini | AI Brain |
| Whisper API | Voice Transcription |
| OpenAI Embeddings | RAG + Visual Search |
| Guzzle HTTP | HTTP Client |
| Vanilla JS PWA | Dashboard |

---

## 📄 الرخصة | License

MIT License — حر الاستخدام مع الإشارة للمصدر.

---

<div align="center">

**صُنع بـ ❤️ لأتمتة خدمة الزباين عبر واتساب**

</div>
