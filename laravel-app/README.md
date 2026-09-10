# Laravel application — RAG Documents

هذا المجلد هو واجهة Laravel وخدمات التطبيق ولوحة Filament ضمن مشروع RAG متعدد الخدمات.

اتبع [دليل التشغيل الرئيسي](../README.md) لتثبيت المشروع كاملاً على macOS أو Windows، بما فيه PHP/Herd وDocker وFastAPI وOllama، وإعداد البريد الإلكتروني وتفعيل الحساب.

تشغيل هذا المجلد وحده لا يشغّل خدمة الذكاء أو قواعد البيانات أو عمال الخلفية. أوامر Composer وArtisan وnpm وDocker Compose الخاصة بالتطبيق تُنفّذ من هذا المجلد؛ أوامر Python تُنفّذ من `fastapi-app` كما في الدليل.
