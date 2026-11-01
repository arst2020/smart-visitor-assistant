<?php
/**
 * Answer templates, labels and keyword lists (fa / en / de).
 * Everything here can be changed with the filters sva_templates, sva_types, sva_role_keywords, sva_intent_keywords.
 */
defined( 'ABSPATH' ) || exit;

class SVA_I18n {

	private static $tpl = null;

	public static function detect( $text, $fallback = null ) {
		$fallback = $fallback ? $fallback : SVA_Plugin::site_lang();
		$fa       = preg_match_all( '/[\x{0600}-\x{06FF}]/u', $text );
		$la       = preg_match_all( '/[a-zA-ZäöüÄÖÜß]/u', $text );
		if ( ! $fa && ! $la ) {
			return $fallback;
		}
		if ( $fa >= $la ) {
			return 'fa';
		}
		if ( preg_match( '/[äöüß]|\b(ich|und|ist|nicht|wie|wo|wann|termin|bitte|haben|sie|gibt|kann|einen|eine|der|die|das|preis|kosten|öffnungszeiten|hallo|danke)\b/iu', $text ) ) {
			return 'de';
		}
		return 'en';
	}

	public static function t( $key, $lang = 'fa' ) {
		if ( null === self::$tpl ) {
			self::$tpl = apply_filters( 'sva_templates', self::templates() );
		}
		if ( isset( self::$tpl[ $lang ][ $key ] ) ) {
			return self::$tpl[ $lang ][ $key ];
		}
		return isset( self::$tpl['en'][ $key ] ) ? self::$tpl['en'][ $key ] : $key;
	}

	public static function types() {
		return apply_filters(
			'sva_types',
			array(
				'medical'    => 'پزشکی و درمانی',
				'shop'       => 'فروشگاه اینترنتی',
				'restaurant' => 'رستوران و کافه',
				'education'  => 'آموزشی',
				'services'   => 'خدماتی / شرکتی',
				'beauty'     => 'زیبایی و آرایشی',
				'legal'      => 'حقوقی و وکالت',
				'realestate' => 'املاک',
				'travel'     => 'گردشگری و هتل',
				'blog'       => 'وبلاگ و خبری',
				'general'    => 'عمومی',
			)
		);
	}

	/** Site types where visitors usually need an appointment / reservation. */
	public static function booking_types() {
		return array( 'medical', 'beauty', 'legal', 'restaurant', 'travel', 'services' );
	}

	public static function roles() {
		return array(
			'appointment' => array( 'fa' => 'نوبت‌دهی / رزرو', 'en' => 'Appointments', 'de' => 'Terminbuchung' ),
			'contact'     => array( 'fa' => 'تماس با ما', 'en' => 'Contact', 'de' => 'Kontakt' ),
			'about'       => array( 'fa' => 'درباره ما', 'en' => 'About us', 'de' => 'Über uns' ),
			'services'    => array( 'fa' => 'خدمات', 'en' => 'Services', 'de' => 'Leistungen' ),
			'team'        => array( 'fa' => 'پزشکان / تیم ما', 'en' => 'Our team', 'de' => 'Team' ),
			'pricing'     => array( 'fa' => 'تعرفه‌ها و قیمت‌ها', 'en' => 'Pricing', 'de' => 'Preise' ),
			'faq'         => array( 'fa' => 'سؤالات متداول', 'en' => 'FAQ', 'de' => 'FAQ' ),
			'shop'        => array( 'fa' => 'فروشگاه', 'en' => 'Shop', 'de' => 'Shop' ),
			'cart'        => array( 'fa' => 'سبد خرید', 'en' => 'Cart', 'de' => 'Warenkorb' ),
			'checkout'    => array( 'fa' => 'تسویه حساب', 'en' => 'Checkout', 'de' => 'Kasse' ),
			'account'     => array( 'fa' => 'حساب کاربری', 'en' => 'My account', 'de' => 'Mein Konto' ),
			'tracking'    => array( 'fa' => 'پیگیری سفارش', 'en' => 'Order tracking', 'de' => 'Sendungsverfolgung' ),
			'shipping'    => array( 'fa' => 'شرایط ارسال', 'en' => 'Shipping', 'de' => 'Versand' ),
			'returns'     => array( 'fa' => 'بازگشت کالا / گارانتی', 'en' => 'Returns', 'de' => 'Rückgabe' ),
			'location'    => array( 'fa' => 'آدرس و شعب', 'en' => 'Locations', 'de' => 'Standorte' ),
			'blog'        => array( 'fa' => 'وبلاگ / مقالات', 'en' => 'Blog', 'de' => 'Blog' ),
		);
	}

	public static function role_label( $role, $lang = 'fa' ) {
		$r = self::roles();
		if ( ! isset( $r[ $role ] ) ) {
			return $role;
		}
		return isset( $r[ $role ][ $lang ] ) ? $r[ $role ][ $lang ] : $r[ $role ]['en'];
	}

	/** Keywords used to find key pages in titles, slugs and menu labels. */
	public static function role_keywords() {
		return apply_filters(
			'sva_role_keywords',
			array(
				'appointment' => array( 'نوبت', 'نوبت دهی', 'نوبتدهی', 'رزرو', 'وقت ملاقات', 'وقت مشاوره', 'دریافت وقت', 'appointment', 'booking', 'book now', 'book online', 'reservation', 'schedule', 'termin', 'terminbuchung', 'reservierung', 'buchen', 'online termin' ),
				'contact'     => array( 'تماس', 'تماس با ما', 'ارتباط با ما', 'contact', 'contact us', 'kontakt', 'impressum' ),
				'about'       => array( 'درباره ما', 'درباره', 'معرفی', 'about', 'about us', 'uber uns', 'über uns', 'wir uber uns' ),
				'services'    => array( 'خدمات', 'خدمات ما', 'سرویس ها', 'services', 'our services', 'leistungen', 'unsere leistungen', 'angebot' ),
				'team'        => array( 'پزشکان', 'تیم ما', 'کادر', 'کادر درمان', 'متخصصین', 'اساتید', 'مدرسین', 'وکلا', 'team', 'our team', 'doctors', 'staff', 'arzte', 'ärzte', 'unser team' ),
				'pricing'     => array( 'تعرفه', 'تعرفه ها', 'لیست قیمت', 'قیمت ها', 'هزینه ها', 'pricing', 'prices', 'price list', 'preise', 'preisliste', 'tarife' ),
				'faq'         => array( 'سوالات متداول', 'پرسش های متداول', 'پرسش و پاسخ', 'faq', 'frequently asked', 'haufige fragen', 'häufige fragen' ),
				'shop'        => array( 'فروشگاه', 'محصولات', 'shop', 'store', 'products', 'produkte' ),
				'cart'        => array( 'سبد خرید', 'cart', 'basket', 'warenkorb' ),
				'checkout'    => array( 'تسویه حساب', 'پرداخت', 'checkout', 'kasse' ),
				'account'     => array( 'حساب کاربری', 'حساب من', 'پنل کاربری', 'my account', 'mein konto' ),
				'tracking'    => array( 'پیگیری سفارش', 'رهگیری سفارش', 'track order', 'order tracking', 'sendungsverfolgung' ),
				'shipping'    => array( 'شرایط ارسال', 'روش های ارسال', 'هزینه ارسال', 'ارسال کالا', 'shipping', 'delivery', 'versand', 'lieferung' ),
				'returns'     => array( 'بازگشت کالا', 'مرجوعی', 'مرجوع', 'ضمانت بازگشت', 'گارانتی', 'returns', 'refund', 'warranty', 'ruckgabe', 'rückgabe', 'widerruf' ),
				'location'    => array( 'شعب', 'شعبه ها', 'نمایندگی ها', 'آدرس ما', 'locations', 'branches', 'standorte', 'anfahrt' ),
				'blog'        => array( 'وبلاگ', 'مقالات', 'اخبار', 'مجله', 'blog', 'news', 'articles', 'magazin', 'aktuelles' ),
			)
		);
	}

	/** External booking services (link to them = appointment page). */
	public static function booking_domains() {
		return apply_filters(
			'sva_booking_domains',
			array( 'paziresh24', 'nobat.ir', 'doctoreto', 'drnext', 'doctors.ir', 'darmankade', 'nobatdehi', 'calendly.com', 'doctolib', 'jameda', 'booksy', 'setmore', 'simplybook', 'acuityscheduling', 'zocdoc', 'treatwell', 'opentable', 'resy.com', 'cal.com', 'youcanbook' )
		);
	}

	/** Keywords used to guess the site type. */
	public static function type_keywords() {
		return apply_filters(
			'sva_type_keywords',
			array(
				'medical'    => array( 'پزشک', 'دکتر', 'کلینیک', 'مطب', 'درمان', 'بیمار', 'جراحی', 'دندان', 'ویزیت', 'معاینه', 'متخصص', 'فیزیوتراپی', 'پوست', 'آزمایشگاه', 'سونوگرافی', 'بیمارستان', 'ایمپلنت', 'ارتودنسی', 'doctor', 'clinic', 'dental', 'dentist', 'patient', 'treatment', 'surgery', 'medical', 'arzt', 'praxis', 'behandlung', 'zahnarzt', 'patienten', 'therapie' ),
				'shop'       => array( 'محصول', 'فروشگاه', 'خرید', 'سبد', 'تومان', 'ریال', 'تخفیف', 'ارسال رایگان', 'سفارش', 'گارانتی', 'product', 'shop', 'cart', 'shipping', 'buy', 'sale', 'produkt', 'warenkorb', 'versand', 'kaufen' ),
				'restaurant' => array( 'رستوران', 'منو', 'غذا', 'کافه', 'پیتزا', 'برگر', 'کباب', 'restaurant', 'menu', 'food', 'cafe', 'pizza', 'speisekarte', 'gericht' ),
				'education'  => array( 'آموزش', 'دوره', 'کلاس', 'مدرس', 'ثبت نام', 'آزمون', 'آموزشگاه', 'کارگاه', 'وبینار', 'course', 'class', 'training', 'lesson', 'student', 'kurs', 'schulung', 'seminar' ),
				'services'   => array( 'خدمات', 'شرکت', 'پروژه', 'مشاوره', 'نمونه کار', 'مشتریان', 'راهکار', 'طراحی', 'پشتیبانی', 'services', 'company', 'project', 'consulting', 'portfolio', 'solutions', 'dienstleistung', 'unternehmen', 'beratung', 'projekte' ),
				'beauty'     => array( 'آرایشگاه', 'سالن زیبایی', 'ناخن', 'میکاپ', 'اپیلاسیون', 'کراتین', 'لیزر موهای', 'salon', 'beauty', 'nails', 'makeup', 'friseur', 'kosmetik' ),
				'legal'      => array( 'وکیل', 'وکالت', 'حقوقی', 'دادگاه', 'دعوی', 'lawyer', 'attorney', 'legal', 'rechtsanwalt', 'kanzlei' ),
				'realestate' => array( 'املاک', 'آپارتمان', 'اجاره', 'رهن', 'متراژ', 'ویلا', 'real estate', 'apartment', 'property', 'immobilien', 'wohnung' ),
				'travel'     => array( 'هتل', 'تور', 'اقامت', 'بلیط', 'سفر', 'hotel', 'tour', 'travel', 'flight', 'reise', 'unterkunft' ),
				'blog'       => array( 'مقاله', 'نویسنده', 'یادداشت', 'article', 'author', 'artikel' ),
			)
		);
	}

	/** Visitor intents. Order = priority. */
	public static function intent_keywords() {
		return apply_filters(
			'sva_intent_keywords',
			array(
				'appointment' => array( 'نوبت', 'رزرو', 'وقت بگیرم', 'وقت ملاقات', 'وقت میخوام', 'وقت میخواستم', 'وقت مشاوره', 'گرفتن وقت', 'appointment', 'book', 'booking', 'reserve', 'reservation', 'schedule', 'termin', 'reservier', 'buchen' ),
				'tracking'    => array( 'پیگیری', 'رهگیری', 'سفارشم', 'کد رهگیری', 'track', 'order status', 'where is my order', 'sendungsverfolgung', 'bestellstatus' ),
				'cart'        => array( 'سبد خرید', 'تسویه', 'cart', 'checkout', 'warenkorb', 'kasse' ),
				'returns'     => array( 'مرجوع', 'بازگشت کالا', 'تعویض', 'پس بدم', 'return', 'refund', 'exchange', 'rückgabe', 'ruckgabe', 'umtausch', 'widerruf' ),
				'shipping'    => array( 'ارسال', 'هزینه پست', 'زمان تحویل', 'تحویل', 'پیک', 'shipping', 'delivery', 'versand', 'lieferung', 'liefer' ),
				'hours'       => array( 'ساعت کاری', 'ساعات کاری', 'ساعت کار', 'چه ساعتی', 'چه روزهایی', 'تا ساعت', 'باز هستید', 'بازه', 'تعطیل', 'روزهای کاری', 'hours', 'open', 'opening', 'closed', 'öffnungszeit', 'offnungszeit', 'geöffnet', 'geoffnet', 'sprechzeit' ),
				'address'     => array( 'آدرس', 'نشانی', 'کجاست', 'کجا هستید', 'کجایید', 'لوکیشن', 'مسیر', 'شعبه', 'address', 'where are you', 'location', 'located', 'directions', 'where is', 'adresse', 'standort', 'anfahrt', 'wo sind', 'wo ist', 'wo finde', 'wo befindet' ),
				'contact'     => array( 'تماس', 'شماره', 'تلفن', 'موبایل', 'ایمیل', 'واتساپ', 'ارتباط', 'زنگ', 'contact', 'phone', 'call', 'email', 'e-mail', 'whatsapp', 'kontakt', 'telefon', 'anrufen', 'erreichen' ),
				'price'       => array( 'قیمت', 'هزینه', 'تعرفه', 'چنده', 'چند است', 'چقدر هزینه', 'چقدر میشه', 'چقدر پول', 'تومان', 'ارزان', 'گران', 'price', 'cost', 'how much', 'fee', 'preis', 'kosten', 'kostet', 'gebühr' ),
				'buy'         => array( 'خرید', 'بخرم', 'محصول', 'کالا', 'موجودی', 'موجود', 'میفروشید', 'دارید', 'buy', 'purchase', 'product', 'in stock', 'available', 'do you have', 'do you sell', 'kaufen', 'produkt', 'verfügbar', 'habt ihr', 'haben sie' ),
				'services'    => array( 'خدمات', 'چه کارهایی', 'چه خدماتی', 'سرویس', 'چه کار میکنید', 'services', 'what do you do', 'what do you offer', 'leistungen', 'angebot', 'was bieten' ),
				'team'        => array( 'پزشکان', 'دکترها', 'متخصصین', 'کادر', 'تیم', 'doctors', 'team', 'staff', 'ärzte', 'arzte' ),
				'about'       => array( 'درباره', 'معرفی', 'کی هستید', 'شما کی', 'about', 'who are you', 'über uns', 'uber uns', 'wer seid' ),
				'thanks'      => array( 'ممنون', 'مرسی', 'سپاس', 'تشکر', 'دستت درد نکنه', 'thanks', 'thank you', 'thx', 'danke' ),
				'greeting'    => array( 'سلام', 'درود', 'وقت بخیر', 'روز بخیر', 'عصر بخیر', 'hello', 'hi', 'hey', 'good morning', 'hallo', 'guten tag', 'moin', 'servus' ),
			)
		);
	}

	private static function templates() {
		return array(
			'fa' => array(
				'default_name'      => 'دستیار هوشمند',
				'greet'             => "سلام! 👋 من %1\$s هستم.\n%2\$s\nچطور می‌تونم کمکتون کنم؟",
				'thanks'            => 'خواهش می‌کنم 🌷 اگر سؤال دیگری دارید در خدمتم.',
				'appointment'       => 'برای دریافت نوبت لطفاً به بخش «%s» مراجعه کنید. 📅',
				'appointment_phone' => 'برای هماهنگی و دریافت نوبت می‌توانید با شماره %s تماس بگیرید.',
				'appointment_none'  => 'بخش نوبت‌دهی آنلاین در سایت پیدا نکردم. لطفاً از راه‌های ارتباطی زیر برای هماهنگی استفاده کنید.',
				'cta_appointment'   => '📅 برای دریافت نوبت به بخش «%s» مراجعه کنید.',
				'contact'           => 'راه‌های ارتباط با ما:',
				'phone'             => 'تلفن',
				'email'             => 'ایمیل',
				'address'           => 'آدرس',
				'hours'             => 'ساعات کاری',
				'no_contact'        => 'اطلاعات تماس را در سایت پیدا نکردم؛ لطفاً صفحه تماس را ببینید.',
				'hours_none'        => 'ساعات کاری در سایت ذکر نشده است. برای اطمینان لطفاً تماس بگیرید.',
				'address_none'      => 'آدرس در سایت ذکر نشده است.',
				'products_found'    => 'این محصولات را برایتان پیدا کردم:',
				'price_hidden'      => 'برای استعلام قیمت و دریافت مشاوره لطفاً با کارشناسان فروش در ارتباط باشید:',
				'chat_on'           => 'ارتباط در',
				'product_one'       => '«%1$s»' . "\n" . 'قیمت: %2$s — %3$s',
				'products_none'     => 'محصولی با این مشخصات پیدا نکردم. می‌توانید نام دیگری را امتحان کنید یا فروشگاه را ببینید.',
				'product_ask'       => 'نام یا نوع محصول مورد نظرتان را بنویسید تا برایتان پیدا کنم. 🔎',
				'categories'        => 'دسته‌بندی‌های محصولات:',
				'cart'              => 'برای مشاهده سبد خرید و تکمیل سفارش از این بخش استفاده کنید:',
				'tracking'          => 'برای پیگیری سفارش به این بخش مراجعه کنید:',
				'services'          => 'خدمات ما:',
				'services_more'     => 'برای جزئیات بیشتر روی هر مورد بزنید.',
				'about_topic'       => 'درباره «%s»:',
				'found'             => 'این صفحات به سؤال شما مرتبط هستند:',
				'not_found'         => 'متأسفانه پاسخ دقیقی در سایت پیدا نکردم. 🙏 می‌توانید سؤالتان را طور دیگری بپرسید یا با ما تماس بگیرید.',
				'price_info'        => 'برای اطلاع از قیمت‌ها و تعرفه‌ها این صفحه را ببینید:',
				'rate'              => 'تعداد پیام‌ها زیاد است؛ لطفاً کمی بعد دوباره امتحان کنید.',
				'in_stock'          => 'موجود',
				'out_stock'         => 'ناموجود',
				'view'              => 'مشاهده',
				'q_services'        => 'خدمات شما چیست؟',
				'q_appointment'     => 'چطور نوبت بگیرم؟',
				'q_contact'         => 'آدرس و شماره تماس',
				'q_hours'           => 'ساعات کاری',
				'q_product'         => 'دنبال یک محصول هستم',
				'q_tracking'        => 'پیگیری سفارش',
				'q_shipping'        => 'شرایط ارسال',
				'q_about'           => 'درباره شما',
				'blurb_medical'     => 'می‌توانم خدمات مجموعه را معرفی کنم و برای نوبت‌گیری راهنمایی‌تان کنم.',
				'blurb_shop'        => 'می‌توانم محصول مناسب را برایتان پیدا کنم و در خرید راهنمایی‌تان کنم.',
				'blurb_default'     => 'می‌توانم سایت و خدمات ما را معرفی کنم و به سؤالاتتان پاسخ بدهم.',
				'placeholder'       => 'پیام خود را بنویسید…',
				'send'              => 'ارسال',
				'typing'            => 'در حال نوشتن…',
				'open'              => 'گفتگو با دستیار',
				'close'             => 'بستن',
				'error'             => 'ارتباط برقرار نشد. لطفاً دوباره تلاش کنید.',
				'reset'             => 'شروع گفتگوی جدید',
				'online'            => 'آنلاین',
			),
			'en' => array(
				'default_name'      => 'Smart Assistant',
				'greet'             => "Hi! 👋 I'm %1\$s.\n%2\$s\nHow can I help you?",
				'thanks'            => "You're welcome! Let me know if there's anything else.",
				'appointment'       => 'To book an appointment, please go to “%s”. 📅',
				'appointment_phone' => 'To book an appointment you can call %s.',
				'appointment_none'  => "I couldn't find online booking on this site. Please use the contact options below.",
				'cta_appointment'   => '📅 To book an appointment, visit “%s”.',
				'contact'           => 'How to reach us:',
				'phone'             => 'Phone',
				'email'             => 'Email',
				'address'           => 'Address',
				'hours'             => 'Opening hours',
				'no_contact'        => "I couldn't find contact details; please see the contact page.",
				'hours_none'        => 'Opening hours are not listed on the site. Please call to make sure.',
				'address_none'      => 'The address is not listed on the site.',
				'products_found'    => 'Here is what I found:',
				'price_hidden'      => 'For prices and advice, please contact our sales team:',
				'chat_on'           => 'Chat on',
				'product_one'       => '“%1$s”' . "\n" . 'Price: %2$s — %3$s',
				'products_none'     => "I couldn't find a matching product. Try another name or browse the shop.",
				'product_ask'       => "Type the name or kind of product you're looking for and I'll find it. 🔎",
				'categories'        => 'Product categories:',
				'cart'              => 'View your cart and complete your order here:',
				'tracking'          => 'You can track your order here:',
				'services'          => 'Our services:',
				'services_more'     => 'Tap an item for details.',
				'about_topic'       => 'About “%s”:',
				'found'             => 'These pages are related to your question:',
				'not_found'         => "Sorry, I couldn't find an exact answer on the site. 🙏 Try asking differently or contact us.",
				'price_info'        => 'See prices here:',
				'rate'              => 'Too many messages – please try again in a moment.',
				'in_stock'          => 'In stock',
				'out_stock'         => 'Out of stock',
				'view'              => 'View',
				'q_services'        => 'What services do you offer?',
				'q_appointment'     => 'How do I book an appointment?',
				'q_contact'         => 'Address & phone',
				'q_hours'           => 'Opening hours',
				'q_product'         => "I'm looking for a product",
				'q_tracking'        => 'Track my order',
				'q_shipping'        => 'Shipping',
				'q_about'           => 'About you',
				'blurb_medical'     => 'I can introduce our services and help you book an appointment.',
				'blurb_shop'        => 'I can help you find the right product and guide you through buying.',
				'blurb_default'     => 'I can tell you about this site and our services and answer your questions.',
				'placeholder'       => 'Type your message…',
				'send'              => 'Send',
				'typing'            => 'Typing…',
				'open'              => 'Chat with assistant',
				'close'             => 'Close',
				'error'             => 'Connection failed. Please try again.',
				'reset'             => 'New conversation',
				'online'            => 'Online',
			),
			'de' => array(
				'default_name'      => 'Smarter Assistent',
				'greet'             => "Hallo! 👋 Ich bin %1\$s.\n%2\$s\nWie kann ich helfen?",
				'thanks'            => 'Gern geschehen! Melden Sie sich, wenn Sie noch Fragen haben.',
				'appointment'       => 'Für einen Termin besuchen Sie bitte „%s“. 📅',
				'appointment_phone' => 'Einen Termin können Sie telefonisch unter %s vereinbaren.',
				'appointment_none'  => 'Ich habe keine Online-Terminbuchung gefunden. Bitte nutzen Sie die Kontaktmöglichkeiten unten.',
				'cta_appointment'   => '📅 Termin vereinbaren: „%s“.',
				'contact'           => 'So erreichen Sie uns:',
				'phone'             => 'Telefon',
				'email'             => 'E-Mail',
				'address'           => 'Adresse',
				'hours'             => 'Öffnungszeiten',
				'no_contact'        => 'Ich habe keine Kontaktdaten gefunden; bitte sehen Sie auf der Kontaktseite nach.',
				'hours_none'        => 'Öffnungszeiten sind auf der Website nicht angegeben. Bitte rufen Sie an.',
				'address_none'      => 'Die Adresse ist auf der Website nicht angegeben.',
				'products_found'    => 'Das habe ich gefunden:',
				'price_hidden'      => 'Für Preise und Beratung kontaktieren Sie bitte unser Vertriebsteam:',
				'chat_on'           => 'Chat über',
				'product_one'       => '„%1$s“' . "\n" . 'Preis: %2$s — %3$s',
				'products_none'     => 'Kein passendes Produkt gefunden. Versuchen Sie einen anderen Begriff oder stöbern Sie im Shop.',
				'product_ask'       => 'Schreiben Sie den Namen oder die Art des Produkts, ich suche es für Sie. 🔎',
				'categories'        => 'Produktkategorien:',
				'cart'              => 'Warenkorb und Bestellung abschließen:',
				'tracking'          => 'Hier können Sie Ihre Bestellung verfolgen:',
				'services'          => 'Unsere Leistungen:',
				'services_more'     => 'Für Details auf einen Eintrag tippen.',
				'about_topic'       => 'Zu „%s“:',
				'found'             => 'Diese Seiten passen zu Ihrer Frage:',
				'not_found'         => 'Leider habe ich keine genaue Antwort gefunden. 🙏 Fragen Sie anders oder kontaktieren Sie uns.',
				'price_info'        => 'Preise finden Sie hier:',
				'rate'              => 'Zu viele Nachrichten – bitte versuchen Sie es gleich noch einmal.',
				'in_stock'          => 'Auf Lager',
				'out_stock'         => 'Nicht vorrätig',
				'view'              => 'Ansehen',
				'q_services'        => 'Welche Leistungen bieten Sie?',
				'q_appointment'     => 'Wie bekomme ich einen Termin?',
				'q_contact'         => 'Adresse & Telefon',
				'q_hours'           => 'Öffnungszeiten',
				'q_product'         => 'Ich suche ein Produkt',
				'q_tracking'        => 'Bestellung verfolgen',
				'q_shipping'        => 'Versand',
				'q_about'           => 'Über Sie',
				'blurb_medical'     => 'Ich stelle Ihnen unsere Leistungen vor und helfe bei der Terminbuchung.',
				'blurb_shop'        => 'Ich helfe Ihnen, das passende Produkt zu finden.',
				'blurb_default'     => 'Ich beantworte Ihre Fragen zu dieser Website und unseren Leistungen.',
				'placeholder'       => 'Nachricht schreiben…',
				'send'              => 'Senden',
				'typing'            => 'Schreibt…',
				'open'              => 'Chat mit dem Assistenten',
				'close'             => 'Schließen',
				'error'             => 'Verbindung fehlgeschlagen. Bitte erneut versuchen.',
				'reset'             => 'Neues Gespräch',
				'online'            => 'Online',
			),
		);
	}
}
