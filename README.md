# نظام الاستعلام الذكي عن الوثائق

تطبيق عربي لرفع الوثائق، البحث في محتواها، وطرح الأسئلة عليها باستخدام RAG، مع عرض الإجابات ومصادرها. يتضمن لوحة إدارة للمستخدمين والمستندات ونتائج تقييم الاسترجاع.

يشرح هذا الدليل تنزيل المشروع وتشغيله على **macOS** أو **Windows مباشرةً من PowerShell، بدون WSL**.

## 1. التقنيات المستخدمة

| الجزء | التقنية |
| --- | --- |
| التطبيق والحسابات | Laravel 13 + Fortify |
| الواجهة ولوحة الإدارة | Livewire 4 + Filament 5 + Tailwind CSS 4 |
| خدمة الذكاء الاصطناعي | FastAPI + Python + PyTorch |
| قاعدة البيانات | MySQL 8.4 |
| المهام الخلفية والبث | Redis |
| البحث المتجهي | Qdrant |
| فحص الملفات | ClamAV |
| النماذج المحلية | BGE-M3، BGE Reranker، وQwen عبر Ollama |
| الخدمات السحابية | LlamaParse، Jina AI، Hugging Face |

Laravel وFastAPI وOllama تعمل على جهازك. Docker يشغّل قواعد البيانات وعمال الخلفية والفحص، فلا تحتاج تثبيت هذه الخدمات واحدةً واحدة.

> المسار `hybrid_local` يستخدم النماذج المحلية للاسترجاع، لكن **تحليل الوثائق الجديدة يستخدم LlamaParse السحابي حالياً**؛ لذلك يحتاج مفتاحاً واتصالاً بالإنترنت. لا يُعد المشروع معالجة offline بالكامل.

## 2. متطلبات الجهاز

| الاستخدام | RAM | المعالج | مساحة خالية |
| --- | --- | --- | --- |
| المسار السحابي | **16 GB موصى بها** لتشغيل المشروع والخدمات براحة | معالج حديث 64-bit؛ لا يلزم GPU محلي | نحو 30 GB |
| المسار المحلي | **16 GB حد عملي ضيق، و32 GB مفضلة** | Apple Silicon على Mac؛ أو Windows x64، وNVIDIA مدعوم أفضل للأداء | 40–60 GB على SSD |

على جهاز 16 GB قد تحتاج إغلاق التطبيقات الثقيلة. قبل تشغيل Qwen محلياً، يفحص المشروع الذاكرة المتاحة حسب تقدير النموذج واحتياطي النظام. المثال الحالي يحدد 2 GiB لكل منهما، أي نحو **4 GiB متاحة**، وقد يرفض الطلب إن لم تتوفر. هذا تقدير قابل للضبط وليس سقفاً يضمن استهلاك النموذج. هذه توصيات للمشروع وليست ضمان أداء لكل الأجهزة. التشغيل على CPU ممكن لكنه أبطأ.

جُرّبت النسخة على Mac M4 بذاكرة 16 GiB. تعليمات Windows مطابقة لطريقة التشغيل والأدوات، لكنها لم تُختبر كتثبيت كامل على جهاز Windows ضمن هذه المراجعة.

## 3. البرامج المطلوبة

ثبّت البرامج التالية ثم افتح Terminal أو PowerShell جديداً:

| البرنامج | الإصدار | رابط التنزيل / الغرض |
| --- | --- | --- |
| Laravel Herd | اختر **PHP 8.4.x، على الأقل 8.4.1** داخله | [Mac](https://herd.laravel.com/docs/macos/getting-started/installation) · [Windows](https://herd.laravel.com/docs/windows/getting-started/installation) — PHP وComposer وتشغيل الموقع |
| Node.js | **22.x، على الأقل 22.12.0** | [التنزيل](https://nodejs.org/en/download)؛ يمكن إدارته من Herd |
| Python | **3.12.x حصراً** | [التنزيل](https://www.python.org/downloads/) — على Windows اختر x64 مع Python Launcher |
| Docker Desktop | إصدار مدعوم على نظامك، مع Compose v2 | [Mac](https://docs.docker.com/desktop/setup/install/mac-install/) · [Windows](https://docs.docker.com/desktop/setup/install/windows-install/) |
| Git | 2.x | [التنزيل](https://git-scm.com/downloads) |
| Ollama | إصدار يدعم `qwen3.5:4b`؛ جُرّبت النسخة 0.33.2 | [Mac](https://docs.ollama.com/macos) · [Windows](https://docs.ollama.com/windows) — للمسار المحلي فقط |

**لماذا PHP 8.4؟** رغم أن `composer.json` يسمح بـ8.3، فإن الحزم المثبتة في `composer.lock` تحتاج 8.4.1 أو أحدث. استخدم Composer 2 المرفق مع Herd. لا تحتاج Herd Pro أو XAMPP أو MySQL منفصلاً لهذا الإعداد.

### إعداد Docker على Windows بدون WSL

1. استخدم **Windows 11 Pro أو Enterprise أو Education x64** بإصدار مدعوم من Docker. Windows Home لا يدعم مسار Hyper-V الموضح هنا.
2. فعّل virtualization في BIOS/UEFI إذا كانت معطلة.
3. من **Turn Windows features on or off** فعّل **Hyper-V** و**Containers**، ثم أعد التشغيل إن طُلب.
4. ثبّت Docker Desktop بخيار **All users**، واختر **Hyper-V** بدلاً من WSL 2.
5. شغّل Docker في وضع **Linux containers**؛ صور المشروع مبنية على Linux.

لا تحتاج Ubuntu أو أوامر WSL؛ PHP وPython وOllama تعمل على Windows نفسه، وحاويات الخدمات يعملها Docker عبر Hyper-V. [مرجع Docker الرسمي](https://docs.docker.com/desktop/setup/install/windows-install/).

على Mac اختر نسخة Docker المناسبة لمعالجك، وافتح Docker Desktop وHerd قبل المتابعة.

### التأكد من التثبيت

على النظامين:

```sh
git --version
php -v
composer --version
node --version
npm --version
docker compose version
```

Python على Mac: `python3.12 --version`، وعلى Windows: `py -3.12 --version`.

إذا فشل Composer بسبب إضافة PHP، افحص `php --ini` و`php -m`. أهم الإضافات هنا: `pdo_mysql`، `redis`، `mbstring`، `intl`، `gd`، `zip`، `curl`، `fileinfo` وإضافات XML. إضافة PHP Redis تختلف عن خادم Redis الموجود في Docker.

## 4. تنزيل المشروع

اختر مجلداً مناسباً مثل `~/Projects` على Mac أو `C:\Projects` على Windows، ثم افتح الطرفية داخله:

```sh
git clone https://github.com/mona-alrayes/RAG_LOCAL_DOCUMETS_SYSTEM.git
cd RAG_LOCAL_DOCUMETS_SYSTEM
git switch main
```

فرع `main` يتضمن إعدادات الذاكرة التي يشرحها الدليل. إن كان المستودع خاصاً، تحتاج حساب GitHub مخولاً بالوصول إليه.

## 5. تجهيز Laravel

من جذر المشروع:

```sh
cd laravel-app
composer install
```

انسخ ملف الإعداد للنسخة الجديدة فقط:

- **Mac:** `cp .env.example .env`
- **Windows:** `Copy-Item .env.example .env`

ثم:

```sh
php artisan key:generate
composer check-platform-reqs
npm ci
npm run build
herd link rag
herd isolate 8.4
```

سيكون عنوان الموقع **http://rag.test**. الربط في Herd يكون لمجلد `laravel-app`، وليس جذر المشروع. قد تظهر أخطاء قاعدة البيانات حتى تكمل الخطوة التالية.

> لا تنسخ `.env.example` فوق إعدادات نسخة مستخدمة، ولا تعِد توليد `APP_KEY` بعد بدء استخدامها.

### إعداد `laravel-app/.env`

احتفظ ببقية قيم المثال، وعدّل التالي. استبدل القيم التي تبدأ بـ`YOUR_` بقيمك:

```dotenv
APP_URL=http://rag.test

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rag_local_documents
DB_USERNAME=rag_app
DB_PASSWORD=YOUR_DB_PASSWORD
MYSQL_ROOT_PASSWORD=YOUR_DIFFERENT_ROOT_PASSWORD

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
QUEUE_CONNECTION=redis

AI_SERVICE_BASE_URL=http://127.0.0.1:8001
AI_SERVICE_INTERNAL_API_KEY=YOUR_INTERNAL_SECRET
AI_SERVICE_TIMEOUT=300
PROCESSING_CALLBACK_SECRET=YOUR_CALLBACK_SECRET
```

لتوليد سر عشوائي، نفّذ هذا الأمر مرتين؛ مرة للمفتاح الداخلي ومرة لمفتاح callback:

```sh
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

احتفظ بالقيمتين لتضعهما أيضاً في إعداد FastAPI. لا ترفعهما إلى Git.

### تشغيل قواعد البيانات والعمال

كل الأوامر التالية من `laravel-app`، وعلى النظامين:

```sh
docker compose up -d mysql redis qdrant
docker compose ps
```

انتظر حتى تصبح MySQL وRedis بحالة healthy، ثم:

```sh
php artisan migrate
php artisan optimize:clear
docker compose build security-worker queue-worker ai-local-worker scheduler
docker compose run --rm security-worker freshclam
docker compose up -d security-worker queue-worker ai-local-worker scheduler
```

الأمر `freshclam` ينزّل توقيعات فحص الملفات؛ يجب أن ينجح قبل رفع الوثائق. البناء والتنزيل الأول قد يستغرقان عدة دقائق. لا تعطّل فحص الملفات إذا فشل تحديث التوقيعات.

## 6. تجهيز FastAPI

افتح طرفية ثانية في **جذر المشروع**.

### macOS

```sh
cd fastapi-app
python3.12 -m venv .venv
source .venv/bin/activate
python -m pip install --upgrade pip
python -m pip install -e '.[local-native]'
cp .env.example .env
```

### Windows — PowerShell

```powershell
cd fastapi-app
py -3.12 -m venv .venv
.\.venv\Scripts\python.exe -m pip install --upgrade pip
.\.venv\Scripts\python.exe -m pip install -e '.[local-native]'
Copy-Item .env.example .env
```

لا تحتاج تفعيل البيئة في PowerShell؛ الأوامر تستخدم Python داخلها مباشرةً. المشروع يتطلب Python 3.12 وPyTorch 2.13.0. إن لم يتوفر إصدار متوافق مع جهازك، راجع رسالة pip ولا تغيّر الإصدارات عشوائياً.

إذا لديك NVIDIA، تحقق من تعريفها ونسخة PyTorch الداعمة لـCUDA وفق [دليل PyTorch](https://pytorch.org/get-started/locally/). وجود البطاقة وحده لا يضمن استخدام GPU.

### إعداد `fastapi-app/.env`

```dotenv
RAG_DEPLOYMENT_MODE=local
LOCAL_AI_TOPOLOGY=host_native
LOCAL_DEVICE=auto
LOCAL_DTYPE=auto

INTERNAL_API_KEY=YOUR_INTERNAL_SECRET
PROCESSING_CALLBACK_SECRET=YOUR_CALLBACK_SECRET
LARAVEL_INTERNAL_BASE_URL=http://rag.test
QDRANT_URL=http://127.0.0.1:6333

LLAMA_CLOUD_API_KEY=YOUR_LLAMA_CLOUD_KEY
RAG_GENERATION_PROFILE=hybrid_local

OLLAMA_BASE_URL=http://127.0.0.1:11435
LOCAL_LLM_MODEL=qwen3.5:4b
OLLAMA_KEEP_ALIVE=5m
LOCAL_LLM_NUM_CTX=8192
RAG_GENERATION_MAX_TOKENS=768
```

اترك إعدادات BGE وحدود الذاكرة الأخرى كما في المثال.

**التطابق المطلوب بين الملفين:**

| Laravel | FastAPI |
| --- | --- |
| `AI_SERVICE_INTERNAL_API_KEY` | نفس قيمة `INTERNAL_API_KEY` |
| `PROCESSING_CALLBACK_SECRET` | نفس قيمة `PROCESSING_CALLBACK_SECRET` |
| عنوان الموقع `APP_URL` | عنوان يصل إليه FastAPI في `LARAVEL_INTERNAL_BASE_URL` |

`RAG_GENERATION_PROFILE=hybrid_local` مهم: اختيار الوضع المحلي وحده لا يحدد مزود الإجابة؛ بدون هذا الإعداد قد يستخدم التطبيق التوليد السحابي.

### روابط مفاتيح المسار السحابي

| الخدمة | رابط الحصول على المفتاح | المتغير في FastAPI |
| --- | --- | --- |
| LlamaCloud / LlamaParse | [لوحة LlamaCloud](https://cloud.llamaindex.ai/) ← تسجيل الدخول ← **API Keys** | `LLAMA_CLOUD_API_KEY` |
| Jina AI | [لوحة API في Jina](https://jina.ai/api-dashboard/) ← الحصول على مفتاح لحسابك | `JINAAI_API_KEY` |
| Hugging Face | [Access Tokens](https://huggingface.co/settings/tokens) ← **Create new token** | `HF_TOKEN` |

في Hugging Face أنشئ token من نوع fine-grained بصلاحية **Make calls to Inference Providers**، وتحقق من توفر الرصيد/المزود للنموذج المستخدم. [شرح الصلاحية الرسمي](https://huggingface.co/docs/inference-providers/en/index).

LlamaParse مطلوب لمعالجة الملفات في كلا المسارين. Jina وHugging Face مطلوبان للمسار السحابي. لا تحتاج شراء مفتاح من OpenAI لتشغيل الإعداد الحالي.

**إذا أردت المسار السحابي بدلاً من المحلي:** اضبط `RAG_DEPLOYMENT_MODE=cloud` و`RAG_GENERATION_PROFILE=cloud`، وأضف المفاتيح الثلاثة، ثم **احذف أو علّق `LOCAL_AI_TOPOLOGY`**. لا تحتاج Ollama، ويمكن تثبيت Python بـ`python -m pip install -e .` دون `local-native`. تبقى بقية الخدمات وقيم الاتصال مطلوبة.

## 7. تشغيل Ollama والنموذج المحلي

هذه الخطوة للمسار المحلي فقط. افتح نافذة مستقلة واتركها تعمل.

**Mac — من جذر المشروع:**

```sh
./scripts/run-local-ollama.sh
```

**Windows — PowerShell:**

```powershell
$env:OLLAMA_HOST = '127.0.0.1:11435'
$env:OLLAMA_NUM_PARALLEL = '1'
$env:OLLAMA_MAX_LOADED_MODELS = '1'
$env:OLLAMA_FLASH_ATTENTION = '1'
$env:OLLAMA_KV_CACHE_TYPE = 'q8_0'
$env:OLLAMA_CONTEXT_LENGTH = '8192'
ollama serve
```

في نافذة أخرى نزّل النموذج **مرة واحدة**:

**Mac:**

```sh
OLLAMA_HOST=127.0.0.1:11435 ollama pull qwen3.5:4b
```

**Windows:**

```powershell
$env:OLLAMA_HOST = '127.0.0.1:11435'
ollama pull qwen3.5:4b
```

الإعدادات أعلاه تخص خادم Ollama نفسه؛ وضعها في `.env` الخاص بـFastAPI فقط لا يطبّقها. لا تحتاج تشغيل `ollama run` بجانبه.

### تنزيل tokenizer

يستخدمه المشروع لحساب طول السؤال والمقاطع ضمن السياق. من `fastapi-app`:

**Mac بعد تفعيل `.venv`:**

```sh
python -c "from huggingface_hub import hf_hub_download; print(hf_hub_download('Qwen/Qwen3.5-4B', 'tokenizer.json'))"
```

**Windows:**

```powershell
.\.venv\Scripts\python.exe -c "from huggingface_hub import hf_hub_download; print(hf_hub_download('Qwen/Qwen3.5-4B', 'tokenizer.json'))"
```

انسخ المسار الناتج إلى `fastapi-app/.env`:

```dotenv
LOCAL_LLM_TOKENIZER_PATH="THE_ABSOLUTE_PATH_PRINTED_ABOVE"
```

على Windows يمكن كتابة المسار باستخدام `/` مثل `C:/Users/.../tokenizer.json`. عند تغيير Qwen إلى نموذج آخر، استخدم tokenizer مطابقاً له. نماذج BGE تُنزّل عند أول استخدام إذا لم تكن في cache؛ لذلك أول معالجة قد تكون أبطأ وتحتاج الإنترنت.

## 8. البريد الإلكتروني عبر Gmail SMTP

البريد مسؤول عنه **Laravel فقط**. يستخدمه المشروع لإرسال رابط تفعيل الحساب واستعادة كلمة المرور.

### إنشاء App Password

1. افتح [أمان حساب Google](https://myaccount.google.com/security).
2. فعّل **التحقق بخطوتين — 2-Step Verification**.
3. افتح [App Passwords](https://myaccount.google.com/apppasswords).
4. أنشئ كلمة مرور تطبيق باسم مثل `RAG Project`، واحتفظ بالقيمة في ملف `.env` فقط.

استخدم App Password المولدة، **وليس كلمة مرور Gmail العادية**. قد لا يتاح الخيار لبعض الحسابات المؤسسية أو المحمية؛ راجع [تعليمات Google](https://support.google.com/accounts/answer/185833).

### إعداد Laravel

عدّل `laravel-app/.env`:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-account@gmail.com
MAIL_PASSWORD="YOUR_GOOGLE_APP_PASSWORD"
MAIL_FROM_ADDRESS=your-account@gmail.com
MAIL_FROM_NAME="نظام الاستعلام الذكي عن الوثائق"
```

استخدم عنوان Gmail نفسه في `MAIL_USERNAME` و`MAIL_FROM_ADDRESS`، وأدخل App Password دون مسافات العرض. المنفذ 587 يستخدم STARTTLS. المشروع يقرأ **`MAIL_SCHEME`**؛ لا تعتمد على `MAIL_ENCRYPTION` من شروحات Laravel القديمة. احذف `MAIL_URL` إن كنت قد أضفته لخدمة أخرى.

ثم من `laravel-app`:

```sh
php artisan config:clear
php artisan queue:restart
```

**ملاحظة الإعداد الحالي:** عند مراجعة ملفات البيئة كانت قيمة البريد `MAIL_MAILER=log`، أي تسجل الرسائل ولا ترسلها إلى Gmail. يلزم إدخال حسابك وApp Password وتغييرها إلى `smtp` لتفعيل الإرسال الحقيقي. لا توجد بيانات Gmail شخصية في ملفات المثال.

### اختبار التفعيل

1. افتح `http://rag.test/register` وسجّل حساباً ببريد تستطيع قراءته.
2. افتح رسالة التفعيل في Inbox أو Spam.
3. اضغط الرابط في المتصفح الذي سجلت فيه بالحساب.
4. إذا انتهت صلاحية الرابط، استخدم «إعادة إرسال رابط التفعيل».

إذا لم تصل الرسالة، راجع `storage/logs/laravel.log` وتحقق من `MAIL_MAILER` وApp Password. إذا تغيرت كلمة مرور Google، قد تحتاج إنشاء App Password جديدة. رابط `rag.test` محلي؛ افتحه على جهاز المشروع، فإرسال البريد لا يجعل الموقع متاحاً على الإنترنت.

## 9. تشغيل FastAPI وفتح المشروع

من مجلد `fastapi-app`، واترك النافذة مفتوحة:

**Mac:**

```sh
source .venv/bin/activate
python -m uvicorn app.main:app --host 127.0.0.1 --port 8001
```

**Windows:**

```powershell
.\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8001
```

شغّل نسخة واحدة من FastAPI؛ لا تستخدم عدة workers للنماذج المحلية.

افتح **http://rag.test**، سجّل حساباً وفعّل بريده. لمنح حسابك صلاحية لوحة الإدارة، من `laravel-app`:

```sh
php artisan rag:admin your-account@gmail.com
```

ثم افتح **http://rag.test/admin**. هذا الأمر يحتاج حساباً موجوداً وبريداً مفعلاً؛ لا توجد كلمة مرور مدير جاهزة.

### أول تجربة

1. ارفع وثيقة صغيرة بصيغة PDF أو DOCX أو TXT.
2. اختر المسار المجهز لديك، وانتظر اكتمال الفحص والمعالجة.
3. افتح محادثة وحدد الوثيقة واسأل عن معلومة فيها.
4. راجع الإجابة والمصادر المعروضة.

من لوحة التقييم يمكن تنزيل قالب dataset ورفعه بصيغة **Excel `.xlsx` أو JSON**، حتى 100 سؤال / 1 MiB. استخدم أرقام الوثائق والمقاطع الفعلية. المقاييس المنفذة هنا تقيس **الاسترجاع**: Precision، Recall، Hit Rate، MRR وnDCG عند K؛ وجود إجابة مرجعية لا يعني احتساب Faithfulness أو Correctness تلقائياً.

## 10. التشغيل اليومي والإيقاف

في كل مرة:

1. افتح Herd وDocker Desktop.
2. من `laravel-app` شغّل `docker compose up -d`.
3. شغّل Ollama ثم FastAPI بالأوامر السابقة للمسار المحلي؛ السحابي يحتاج FastAPI فقط.
4. افتح `http://rag.test`.

لا تحتاج إعادة تنزيل النماذج أو تشغيل migrations يومياً. لتطوير الواجهة شغّل `npm run dev` من `laravel-app`؛ للتشغيل العادي يكفي `npm run build` بعد تعديل ملفات الواجهة.

للإيقاف: انتظر انتهاء المهام، ثم Ctrl+C في نوافذ FastAPI/Ollama/Vite، ومن `laravel-app`:

```sh
docker compose stop
```

لا تستخدم `docker compose down -v` للإيقاف العادي؛ يحذف volumes وبياناتها.

## 11. المشاكل الشائعة

| المشكلة | الحل الأول |
| --- | --- |
| خطأ إصدار PHP | اختر 8.4 في Herd وتحقق من `php -v` |
| خطأ إضافة PHP | شغّل `composer check-platform-reqs` وفعّل الإضافة المطلوبة في PHP الصحيح |
| الملف عالق بانتظار المعالجة | افحص `docker compose ps` وتوقيعات ClamAV ومفاتيح الخدمات |
| البريد لا يصل | استخدم Gmail App Password و`MAIL_MAILER=smtp`، ثم امسح config cache |
| FastAPI يعيد 401 | طابق المفتاحين؛ حتى health يحتاج `X-Internal-API-Key` |
| `local_resource_exhausted` | أغلق التطبيقات الثقيلة؛ RAM المتاحة لا تكفي للمرحلة التالية |
| `local_prompt_too_large` | تحقق من tokenizer المطابق وقلل نطاق السؤال/الوثائق |
| Ollama لا يجد النموذج | تأكد أنك حمّلته للخادم على 11435 وأن الاسم مطابق |
| العامل لا يصل إلى FastAPI | Compose يستخدم `host.docker.internal:8001`؛ افحص Docker وجدار الحماية، ولا تغيّر العنوان عشوائياً |
| تغيّرت `.env` ولم تتطبق | أعد تشغيل FastAPI، واستخدم `php artisan config:clear` لـLaravel |

بعد تغيير بيئة عمال Docker، أعد إنشاءهم من `laravel-app`:

```sh
docker compose up -d --force-recreate security-worker queue-worker ai-local-worker scheduler
```

للسجلات:

```sh
docker compose logs --tail=100 security-worker queue-worker ai-local-worker
```

وسجل Laravel موجود في `laravel-app/storage/logs/laravel.log`. داخل Docker عناوين قواعد البيانات مختلفة عن المضيف؛ Compose يضبطها تلقائياً، فلا تستبدل `DB_HOST=127.0.0.1` في ملف المضيف بـ`mysql`.

## ملفات مهمة

- [Laravel env example](laravel-app/.env.example) و[FastAPI env example](fastapi-app/.env.example).
- [فيديو المشروع على Google Drive](https://drive.google.com/file/d/10y38-dXDZlssKWOq_r0Jrboj3LtKzQ3-/view?usp=sharing).

ملفا `.env` يحتويان أسراراً محلية ولا يُرفعان إلى Git. تنزيل المستودع لا ينقل قاعدة البيانات أو الوثائق أو فهارس Qdrant من جهاز آخر.
