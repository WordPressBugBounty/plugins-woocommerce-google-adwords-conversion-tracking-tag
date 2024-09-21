<?php

namespace SweetCode\Pixel_Manager\Admin;

use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

class Borlabs_Presets {

	public static function get_cookie_presets() {

		return [
			'adroll-ads'       => [
				'active'             => Options::is_adroll_active(),
				'group_id'           => 'marketing',
				'name'               => [
					'en' => 'Adroll',
					'de' => 'Adroll',
				],
				'provider'           => 'adroll.com',
				'purpose'            => [
					'en' => 'Adroll is an advertising service provided by Adroll Inc. This website uses the Adroll conversion tracking feature to measure the performance of advertisements on the platform.',
					'de' => 'Adroll ist ein Werbedienst von Adroll Inc. Diese Website verwendet die Adroll Conversion-Tracking-Funktion, um die Leistung von Werbung auf der Plattform zu messen.',
				],
				'privacy_policy_url' => 'https://www.nextroll.com/privacy',
			],
			'taboola-ads'      => [
				'active'             => Options::is_taboola_active(),
				'group_id'           => 'marketing',
				'name'               => [
					'en' => 'Taboola',
					'de' => 'Taboola',
				],
				'provider'           => 'taboola.com',
				'purpose'            => [
					'en' => 'Taboola is an advertising service provided by Taboola Inc. This website uses the Taboola conversion tracking feature to measure the performance of advertisements on the platform.',
					'de' => 'Taboola ist ein Werbedienst von Taboola Inc. Diese Website verwendet die Taboola Conversion-Tracking-Funktion, um die Leistung von Werbung auf der Plattform zu messen.',
				],
				'privacy_policy_url' => 'https://www.taboola.com/policies/privacy-policy',
			],
			'outbrain-ads'     => [
				'active'             => Options::is_outbrain_active(),
				'group_id'           => 'marketing',
				'name'               => [
					'en' => 'Outbrain',
					'de' => 'Outbrain',
				],
				'provider'           => 'outbrain.com',
				'purpose'            => [
					'en' => 'Outbrain is an advertising service provided by Outbrain Inc. This website uses the Outbrain conversion tracking feature to measure the performance of advertisements on the platform.',
					'de' => 'Outbrain ist ein Werbedienst von Outbrain Inc. Diese Website verwendet die Outbrain Conversion-Tracking-Funktion, um die Leistung von Werbung auf der Plattform zu messen.',
				],
				'privacy_policy_url' => 'https://www.outbrain.com/privacy/',
			],
			'linkedin-ads'     => [
				'active'             => Options::is_linkedin_active(),
				'group_id'           => 'marketing',
				'name'               => [
					'en' => 'LinkedIn Ads',
					'de' => 'LinkedIn Ads',
				],
				'provider'           => 'linkedin.com',
				'purpose'            => [
					'en' => 'LinkedIn Ads is an advertising service provided by LinkedIn Ireland Unlimited Company. This website uses the LinkedIn Ads conversion tracking feature to measure the performance of advertisements on the platform.',
					'de' => 'LinkedIn Ads ist ein Werbedienst von LinkedIn Ireland Unlimited Company. Diese Website verwendet die LinkedIn Ads Conversion-Tracking-Funktion, um die Leistung von Werbung auf der Plattform zu messen.',
				],
				'privacy_policy_url' => 'https://www.linkedin.com/legal/privacy-policy',
			],
			'twitter-ads'      => [
				'active'             => Options::is_twitter_active(),
				'group_id'           => 'marketing',
				'name'               => [
					'en' => 'Twitter Ads',
					'de' => 'Twitter Ads',
				],
				'provider'           => 'twitter.com',
				'purpose'            => [
					'en' => 'Twitter Ads is an advertising service provided by Twitter Inc. This website uses the Twitter Ads conversion tracking feature to measure the performance of advertisements on the platform.',
					'de' => 'Twitter Ads ist ein Werbedienst von Twitter Inc. Diese Website verwendet die Twitter Ads Conversion-Tracking-Funktion, um die Leistung von Werbung auf der Plattform zu messen.',
				],
				'privacy_policy_url' => 'https://twitter.com/privacy',
			],
			'wpm-cookie'       => [
				'active'      => true,
				'group_id'    => 'essential',
				'name'        => [
					'en' => 'Pixel Manager for WooCommerce',
					'de' => 'Pixel Manager für WooCommerce',
				],
				'provider'    => 'sweetcode.com',
				'purpose'     => [
					'en' => 'The cookies are being used to better track the user state within the user browser, such as the cart contents. The information then is passed on to various tracking platforms, but only if they are active and and not blocked by Borlabs. All information saved by Pixel Manager cookies is only being shared by the browser of the visitor and the WooCommerce store that he is visiting.',
					'de' => 'Die Cookies werden verwendet, um den Benutzerstatus innerhalb des Benutzerbrowsers besser zu verfolgen, z. B. den Warenkorb. Die Informationen werden dann an verschiedene Tracking-Plattformen weitergegeben, jedoch nur, wenn sie aktiv sind und nicht von Borlabs blockiert werden. Alle von Pixel Manager-Cookies gespeicherten Informationen werden nur vom Browser des Besuchers und dem WooCommerce-Shop, den er besucht, gemeinsam genutzt.',
				],
				'cookie_name' => 'wpmReferrer,wpm_cid_*,_wpm_order_ids,pmwReferrer,pmw_cie_*,_pmw_order_ids',
			],
			'wooptpm-cookie'   => [
				'active'      => true,
				'group_id'    => 'essential',
				'name'        => [
					'en' => 'Pixel Manager for WooCommerce',
					'de' => 'Pixel Manager for WooCommerce',
				],
				'provider'    => 'sweetcode.com',
				'purpose'     => [
					'en' => 'The cookies are being used to better track the user state within the user browser, such as the cart contents. The information then is passed on to various tracking platforms, but only if they are active and and not blocked by Borlabs. All information saved by Pixel Manager cookies is only being shared by the browser of the visitor and the WooCommerce store that he is visiting.',
					'de' => 'Die Cookies werden verwendet, um den Benutzerstatus innerhalb des Benutzerbrowsers besser zu verfolgen, z. B. den Warenkorb. Die Informationen werden dann an verschiedene Tracking-Plattformen weitergegeben, jedoch nur, wenn sie aktiv sind und nicht von Borlabs blockiert werden. Alle von Pixel Manager-Cookies gespeicherten Informationen werden nur vom Browser des Besuchers und dem WooCommerce-Shop, den er besucht, gemeinsam genutzt.',
				],
				'cookie_name' => 'wpmReferrer,wpm_cid_*,_wpm_order_ids,pmwReferrer,pmw_cie_*,_pmw_order_ids',
			],
			'tiktok-ads'       => [
				'active'   => Options::is_tiktok_active(),
				'group_id' => 'marketing',
				'name'     => [
					'en' => 'TikTok Ads',
					'de' => 'TikTok Ads',
				],
				'provider' => 'tiktok.com',
				'purpose'  => [
					'en' => 'TikTok Ads is an advertising service provided by TikTok Inc. This website uses the TikTok Ads conversion tracking feature to measure the performance of advertisements on the platform.',
					'de' => 'TikTok Ads ist ein Werbedienst von TikTok Inc. Diese Website verwendet die TikTok Ads Conversion-Tracking-Funktion, um die Leistung von Werbung auf der Plattform zu messen.',
				],
			],
			'snapchat-ads'     => [
				'active'   => Options::is_snapchat_active(),
				'group_id' => 'marketing',
				'name'     => [
					'en' => 'Snapchat Ads',
					'de' => 'Snapchat Ads',
				],
				'provider' => 'snapchat.com',
				'purpose'  => [
					'en' => 'Snapchat Ads is an advertising service provided by Snapchat Inc. This website uses the Snapchat Ads conversion tracking feature to measure the performance of advertisements on the platform.',
					'de' => 'snapchat.com',
				],
			],
			'pinterest-ads'    => [
				'active'   => Options::is_pinterest_active(),
				'group_id' => 'marketing',
				'name'     => [
					'en' => 'Pinterest Ads',
					'de' => 'Pinterest Ads',
				],
				'provider' => 'pinterest.com',
				'purpose'  => [
					'en' => 'Pinterest Ads is an advertising service provided by Pinterest Inc. This website uses the Pinterest Ads conversion tracking feature to measure the performance of advertisements on the platform.',
					'de' => 'Pinterest Ads ist ein Werbedienst von Pinterest Inc. Diese Website verwendet die Pinterest Ads Conversion-Tracking-Funktion, um die Leistung von Werbung auf der Plattform zu messen.',
				],
			],
			'facebook-ads'     => [
				'active'             => Options::is_facebook_active(),
				'group_id'           => 'marketing',
				'name'               => [
					'en' => 'Meta (Facebook) Ads',
					'de' => 'Meta (Facebook) Ads',
				],
				'provider'           => 'facebook.com',
				'purpose'            => [
					'en' => 'Facebook Ads is an advertising service provided by Meta Platforms Inc. This website uses the Facebook Ads conversion tracking feature to measure the performance of advertisements on the platform.',
					'de' => 'Facebook Ads ist ein Werbedienst von Meta Platforms Inc. Diese Website verwendet die Facebook Ads Conversion-Tracking-Funktion, um die Leistung von Werbung auf der Plattform zu messen.',
				],
				'cookie_name'        => '_fbp,act,c_user,datr,fr,m_pixel_ration,pl,presence,sb,spin,wd,xs',
				'privacy_policy_url' => 'https://www.facebook.com/policies/cookies',
			],
			'microsoft-ads'    => [
				'active'   => Options::is_bing_active(),
				'group_id' => 'marketing',
				'name'     => [
					'en' => 'Microsoft Ads',
					'de' => 'Microsoft Ads',
				],
				'provider' => 'microsoft.com',
				'purpose'  => [
					'en' => 'Microsoft Ads is an advertising service provided by Microsoft Corporation. This website uses the Microsoft Ads conversion tracking feature to measure the performance of advertisements on the platform.',
					'de' => 'Microsoft Ads ist ein Werbedienst von Microsoft Corporation. Diese Website verwendet die Microsoft Ads Conversion-Tracking-Funktion, um die Leistung von Werbung auf der Plattform zu messen.',
				],
			],
			'google-ads'       => [
				'active'             => Options::is_google_ads_active(),
				'group_id'           => 'marketing',
				'name'               => [
					'en' => 'Google Ads',
					'de' => 'Google Ads',
				],
				'provider'           => 'google.com',
				'purpose'            => [
					'en' => 'Google Ads is an advertising service provided by Google LLC. This website uses the Google Ads conversion tracking feature to measure the performance of advertisements on the platform.',
					'de' => 'Google Ads ist ein Werbedienst von Google LLC. Diese Website verwendet die Google Ads Conversion-Tracking-Funktion, um die Leistung von Werbung auf der Plattform zu messen.',
				],
				'privacy_policy_url' => 'https://policies.google.com/privacy',
			],
			'hotjar'           => [
				'active'             => Options::is_hotjar_enabled(),
				'group_id'           => 'statistics',
				'name'               => [
					'en' => 'Hotjar',
					'de' => 'Hotjar',
				],
				'provider'           => 'hotjar.com',
				'purpose'            => [
					'en' => 'Hotjar is a web analytics service provided by Hotjar Ltd. Hotjar uses cookies to collect non-personal information. This information includes the operating system used, the browser type, the IP address, the pages visited, the geographical location, the device and the preferred language for displaying our website. The information is stored in a pseudonymised user profile. The information will not be used by Hotjar or by us to identify individual users or merged with other data about individual users.',
					'de' => 'Hotjar ist ein Web-Analyse-Service von Hotjar Ltd. Hotjar verwendet Cookies, um nicht-personenbezogene Informationen zu sammeln. Diese Informationen umfassen das verwendete Betriebssystem, den Browsertyp, die IP-Adresse, die besuchten Seiten, den geografischen Standort, das Gerät und die bevorzugte Sprache zur Anzeige unserer Website. Die Informationen werden in einem pseudonymisierten Benutzerprofil gespeichert. Die Informationen werden von Hotjar oder von uns nicht verwendet, um einzelne Benutzer zu identifizieren oder mit anderen Daten über einzelne Benutzer zusammengeführt.',
				],
				'cookie_name'        => '_hjClosedSurveyInvites, _hjDonePolls, _hjMinimizedPolls, _hjDoneTestersWidgets, _hjIncludedInSample, _hjShownFeedbackMessage, _hjid, _hjRecordingLastActivity, hjTLDTest, _hjUserAttributesHash, _hjCachedUserAttributes, _hjLocalStorageTest, _hjptid',
				'privacy_policy_url' => 'https://www.hotjar.com/legal/policies/privacy/',
			],
			'google-analytics' => [
				'active'             => Options::is_ga4_enabled(),
				'group_id'           => 'statistics',
				'name'               => [
					'en' => 'Google Analytics',
					'de' => 'Google Analytics',
				],
				'provider'           => 'google.com',
				'purpose'            => [
					'en' => 'Google Analytics is a web analytics service provided by Google LLC. Google Analytics uses cookies to collect non-personal information. This information includes the operating system used, the browser type, the IP address, the pages visited, the geographical location, the device and the preferred language for displaying our website. The information is stored in a pseudonymised user profile. The information will not be used by Google or by us to identify individual users or merged with other data about individual users.',
					'de' => 'Google Analytics ist ein Web-Analyse-Service von Google LLC. Google Analytics verwendet Cookies, um nicht-personenbezogene Informationen zu sammeln. Diese Informationen umfassen das verwendete Betriebssystem, den Browsertyp, die IP-Adresse, die besuchten Seiten, den geografischen Standort, das Gerät und die bevorzugte Sprache zur Anzeige unserer Website. Die Informationen werden in einem pseudonymisierten Benutzerprofil gespeichert. Die Informationen werden von Google oder von uns nicht verwendet, um einzelne Benutzer zu identifizieren oder mit anderen Daten über einzelne Benutzer zusammengeführt.',
				],
				'cookie_name'        => '_ga,_gat,_gid',
				'privacy_policy_url' => 'https://policies.google.com/privacy',
			],
			//			'youtube'         => [
			//				'group_id' => 'external-media',
			//				'name'     => [
			//					'en' => 'YouTube',
			//					'de' => 'YouTube',
			//				],
			//				'provider' => 'google.com',
			//				'purpose'  => [
			//					'en' => 'YouTube is a video portal of YouTube LLC., a subsidiary of Google LLC. YouTube is a platform that allows users to upload, view, rate, share and comment on videos, subscribe to other users and create playlists. We use YouTube to embed videos on our website.',
			//					'de' => 'YouTube ist ein Videoportal der YouTube LLC., einer Tochtergesellschaft der Google LLC. YouTube ist eine Plattform, die es Benutzern ermöglicht, Videos hochzuladen, anzusehen, zu bewerten, zu teilen und zu kommentieren, sich auf andere Benutzer zu abonnieren und Playlists zu erstellen. Wir verwenden YouTube, um Videos auf unserer Website einzubetten.',
			//				],
			//			],
			//			'vimeo'         => [
			//				'group_id' => 'external-media',
			//				'name'     => [
			//					'en' => 'Vimeo',
			//					'de' => 'Vimeo',
			//				],
			//				'provider' => 'vimdeo.com',
			//				'purpose'  => [
			//					'en' => 'Vimeo is a video portal of Vimeo, Inc. Vimeo is a platform that allows users to upload, view, rate, share and comment on videos, subscribe to other users and create playlists. We use Vimeo to embed videos on our website.',
			//					'de' => 'Vimeo ist ein Videoportal der Vimeo, Inc. Vimeo ist eine Plattform, die es Benutzern ermöglicht, Videos hochzuladen, anzusehen, zu bewerten, zu teilen und zu kommentieren, sich auf andere Benutzer zu abonnieren und Playlists zu erstellen. Wir verwenden Vimeo, um Videos auf unserer Website einzubetten.',
			//				],
			//				'privacy_policy_url' => 'https://vimeo.com/privacy',
			//							'hosts' => ['player.vimeo.com'],
			//				'cookie_name' => 'vuid',
			//				'cookie_expiry' => '2 Years',
			//			],
			'woocommerce'      => [
				'active'      => Environment::is_woocommerce_active(),
				'group_id'    => 'essential',
				'name'        => [
					'en' => 'WooCommerce',
					'de' => 'WooCommerce',
				],
				'provider'    => '',
				'purpose'     => [
					'en' => 'WooCommerce is a WordPress plugin that allows you to create an online store. Cookies are used to store information about the shopping cart.',
					'de' => 'WooCommerce ist ein WordPress-Plugin, mit dem Sie einen Online-Shop erstellen können. Cookies werden verwendet, um Informationen über den Warenkorb zu speichern.',
				],
				'cookie_name' => 'woocommerce_cart_hash,woocommerce_items_in_cart,wp_woocommerce_session_,woocommerce_recently_viewed',
			],
			'reddit-ads'       => [
				'active'   => Options::is_reddit_active(),
				'group_id' => 'marketing',
				'name'     => [
					'en' => 'Reddit Ads',
					'de' => 'Reddit Ads',
					'fr' => 'Reddit Ads',
				],
				'provider' => 'reddit.com',
				'purpose'  => [
					'en' => 'Reddit Ads is an advertising service provided by Reddit Inc. This website uses the Reddit Ads conversion tracking feature to measure the performance of advertisements on the platform.',
					'de' => 'Reddit Ads ist ein Werbedienst von Reddit Inc. Diese Website verwendet die Reddit Ads Conversion-Tracking-Funktion, um die Leistung von Werbung auf der Plattform zu messen.',
					'fr' => 'Reddit Ads est un service de publicité fourni par Reddit Inc. Ce site Web utilise la fonction de suivi des conversions Reddit Ads pour mesurer les performances des publicités sur la plate-forme.',
				],
			],
			//			'matomo'     => [
			//				'group_id' => 'statistics',
			//				'name'     => [
			//					'en' => 'Matomo',
			//					'de' => 'Matomo',
			//					'fr' => 'Matomo',
			//					'it' => 'Matomo',
			//				],
			//				'provider' => 'matomo.com',
			//				'purpose'  => [
			//					'en' => 'Matomo is a tracking and analysis platform that protects the privacy of visitors and users. Store and analyze data such as the time spent on the website, the pages visited, the country of origin, the used search engine and the like. The evaluation is used exclusively for the optimization of the website and the cost-benefit analysis of Internet advertising.',
			//					'de' => 'Matomo ist eine Tracking- und Analyseplattform, die die Privatsphäre von Besuchern und Nutzern schützt. Speichern und analysieren Sie Daten wie die auf der Website verbrachte Zeit, die besuchten Seiten, das Herkunftsland, die verwendete Suchmaschine und dergleichen. Die Auswertung wird ausschließlich zur Optimierung der Website und zur Kosten-Nutzen-Analyse von Internetwerbung verwendet.',
			//					'fr' => 'Matomo est une plate-forme de suivi et d\'analyse qui protège la vie privée des visiteurs et des utilisateurs. Stocker et analyser des données telles que le temps passé sur le site Web, les pages visitées, le pays d\'origine, le moteur de recherche utilisé et autres. L\'évaluation est utilisée exclusivement pour l\'optimisation du site Web et l\'analyse coût-bénéfice de la publicité sur Internet.',
			//				],
			//			],
		];
	}

	public static function get_cookie_group_presets() {

		return [
			'marketing'  => [
				'name'        => [
					'en' => 'Marketing',
					'de' => 'Marketing',
					//					'fr' => 'Marketing',
				],
				'description' => [
					'en' => 'Marketing cookies are used to track visitors across websites. The intention is to display ads that are relevant and engaging for the individual user and thereby more valuable for publishers and third party advertisers.',
					'de' => 'Marketing-Cookies werden verwendet, um Besucher über Websites hinweg zu verfolgen. Die Absicht ist es, Anzeigen zu schalten, die für den einzelnen Nutzer relevant und ansprechend sind und damit für Publisher und Drittanbieter-Anzeigen wertvoller sind.',
					//					'fr' => 'Les cookies marketing sont utilisés pour suivre les visiteurs sur les sites Web. L\'intention est d\'afficher des publicités qui sont pertinentes et engageantes pour l\'utilisateur individuel et donc plus précieux pour les éditeurs et les annonceurs tiers.',
				],
			],
			'statistics' => [
				'name'        => [
					'en' => 'Statistics',
					'de' => 'Statistiken',
					//					'fr' => 'Statistiques',
					//					'it' => 'Statistiche',
				],
				'description' => [
					'en' => 'Statistic cookies help website owners to understand how visitors interact with websites by collecting and reporting information anonymously.',
					'de' => 'Statistik-Cookies helfen Website-Besitzern zu verstehen, wie Besucher mit Websites interagieren, indem sie Informationen anonym sammeln und melden.',
					//					'fr' => 'Les cookies statistiques aident les propriétaires de sites Web à comprendre comment les visiteurs interagissent avec les sites Web en collectant et en signalant des informations de manière anonyme.',
					//					'it' => 'I cookie statistici aiutano i proprietari di siti Web a capire come i visitatori interagiscono con i siti Web raccogliendo e segnalando informazioni in modo anonimo.',
				],
			],
			'essential'  => [
				'name'        => [
					'en' => 'Essential',
					'de' => 'Essenziell',
					//					'fr' => 'Essentiel',
					//					'it' => 'Essenziale',
				],
				'description' => [
					'en' => 'Essential cookies enable basic functions and are necessary for the proper functioning of the website.',
					'de' => 'Essenzielle Cookies ermöglichen grundlegende Funktionen und sind für die einwandfreie Funktion der Website erforderlich.',
				],
			],
		];
	}
}
