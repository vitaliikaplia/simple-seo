<?php
/**
 * Local SEO: what kind of organization the site represents and how to find it — the type of
 * schema.org (an organization, or a local business: a dentist, a shop, a law office), the
 * description, address, phone and e-mail, the area served and, for a local business, the
 * coordinates, the map, the opening hours and the price range (decisions/schema).
 *
 * They go into the organization node of the Schema graph (Simple_SEO_Schema::publisher()): the
 * node keeps its @id and takes the chosen type. The settings are on the tab Local SEO; the texts
 * (description, street, city, region, area served, price range) are kept per language with wp-loc.
 * The imports from SEO plugins and the button "Take from the theme" (WP Base and its forks keep
 * these details in their options) fill only what is empty.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Local {
	public const OPTION = 'simple_seo_local';

	/** The texts, kept per language with wp-loc. */
	public const NAMES = 'simple_seo_local_names';

	/** Days of the week as the settings key them => as schema.org names them. */
	public const DAYS = array(
		'Mo' => 'Monday',
		'Tu' => 'Tuesday',
		'We' => 'Wednesday',
		'Th' => 'Thursday',
		'Fr' => 'Friday',
		'Sa' => 'Saturday',
		'Su' => 'Sunday',
	);

	/**
	 * The types one may choose => whether it is a local business (a place with an address, hours
	 * and a map). Filter: simple_seo_local_types.
	 */
	public const TYPES = array(
		'Organization'                => false,
		'Corporation'                 => false,
		'OnlineBusiness'              => false,
		'OnlineStore'                 => false,
		'NGO'                         => false,
		'NewsMediaOrganization'       => false,
		'EducationalOrganization'     => false,
		'GovernmentOrganization'      => false,
		'MedicalOrganization'         => false,
		'SportsOrganization'          => false,
		'PerformingGroup'             => false,
		'LocalBusiness'               => true,
		'ProfessionalService'         => true,
		'LegalService'                => true,
		'Attorney'                    => true,
		'Notary'                      => true,
		'AccountingService'           => true,
		'FinancialService'            => true,
		'InsuranceAgency'             => true,
		'RealEstateAgent'             => true,
		'TravelAgency'                => true,
		'EmploymentAgency'            => true,
		'MedicalClinic'               => true,
		'Dentist'                     => true,
		'Physician'                   => true,
		'Pharmacy'                    => true,
		'Optician'                    => true,
		'HealthAndBeautyBusiness'     => true,
		'BeautySalon'                 => true,
		'HairSalon'                   => true,
		'DaySpa'                      => true,
		'ExerciseGym'                 => true,
		'FoodEstablishment'           => true,
		'Restaurant'                  => true,
		'CafeOrCoffeeShop'            => true,
		'BarOrPub'                    => true,
		'Bakery'                      => true,
		'LodgingBusiness'             => true,
		'Hotel'                       => true,
		'Store'                       => true,
		'ClothingStore'               => true,
		'ElectronicsStore'            => true,
		'FurnitureStore'              => true,
		'GroceryStore'                => true,
		'HardwareStore'               => true,
		'AutomotiveBusiness'          => true,
		'AutoDealer'                  => true,
		'AutoRepair'                  => true,
		'HomeAndConstructionBusiness' => true,
		'GeneralContractor'           => true,
		'Electrician'                 => true,
		'Plumber'                     => true,
		'HVACBusiness'                => true,
		'ChildCare'                   => true,
		'EntertainmentBusiness'       => true,
	);

	private const NONCE = 'simple_seo_local_theme';

	/** @var array<string, bool>|null */
	private static ?array $types = null;

	public static function init(): void {
		Simple_SEO_Settings::register(
			self::OPTION,
			array(
				'type'        => 'Organization',
				'phone'       => '',
				'email'       => '',
				'postal_code' => '',
				'country'     => '',
				// Latitude and longitude, "50.4501,30.5234".
				'geo'         => '',
				'map'         => '',
				// Day => opens, closes ("09:00"); only the days it is open.
				'hours'       => array(),
			),
			array( self::class, 'sanitize' ),
			true
		);
		Simple_SEO_Settings::register(
			self::NAMES,
			array(
				'description' => '',
				'street'      => '',
				'locality'    => '',
				'region'      => '',
				'area_served' => '',
				'price_range' => '',
			),
			array( self::class, 'sanitize_names' ),
			true,
			true
		);

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
			add_action( 'load-toplevel_page_simple-seo', array( self::class, 'handle' ) );
		}
	}


	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab(
			'local',
			__( 'Local SEO', 'simple-seo' ),
			array( self::OPTION, self::NAMES ),
			array( self::class, 'render_tab' ),
			30,
			'markup',
			static fn(): array => array(
				'general' => __( 'General', 'simple-seo' ),
				'address' => __( 'Address and description', 'simple-seo' ),
			)
		);
	}

	/**
	 * The types one may choose => whether each is a local business.
	 *
	 * @return array<string, bool>
	 */
	public static function types(): array {
		if ( null === self::$types ) {
			/**
			 * The schema.org types of the organization one may choose on the tab Local SEO.
			 *
			 * @param array<string, bool> $types Type => whether it is a local business (LocalBusiness or a kind of it),
			 *                                   which gets coordinates, a map, opening hours and a price range.
			 */
			$types = apply_filters( 'simple_seo_local_types', self::TYPES );
			$clean = array();
			foreach ( is_array( $types ) ? $types : array() as $type => $local ) {
				if ( is_string( $type ) && preg_match( '/^[A-Z][A-Za-z]+$/', $type ) ) {
					$clean[ $type ] = (bool) $local;
				}
			}
			self::$types = $clean + array( 'Organization' => false );
		}

		return self::$types;
	}

	/**
	 * The names of the types in the language of the admin; a type the filter added is named as itself.
	 *
	 * @return array<string, string>
	 */
	public static function type_labels(): array {
		$labels = array(
			'Organization'                => __( 'Organization', 'simple-seo' ),
			'Corporation'                 => __( 'Corporation', 'simple-seo' ),
			'OnlineBusiness'              => __( 'Online business', 'simple-seo' ),
			'OnlineStore'                 => __( 'Online store', 'simple-seo' ),
			'NGO'                         => __( 'Non-governmental organization', 'simple-seo' ),
			'NewsMediaOrganization'       => __( 'News media', 'simple-seo' ),
			'EducationalOrganization'     => __( 'Educational organization', 'simple-seo' ),
			'GovernmentOrganization'      => __( 'Government organization', 'simple-seo' ),
			'MedicalOrganization'         => __( 'Medical organization', 'simple-seo' ),
			'SportsOrganization'          => __( 'Sports organization', 'simple-seo' ),
			'PerformingGroup'             => __( 'Performing group', 'simple-seo' ),
			'LocalBusiness'               => __( 'Local business', 'simple-seo' ),
			'ProfessionalService'         => __( 'Professional service', 'simple-seo' ),
			'LegalService'                => __( 'Legal service', 'simple-seo' ),
			'Attorney'                    => __( 'Attorney', 'simple-seo' ),
			'Notary'                      => __( 'Notary', 'simple-seo' ),
			'AccountingService'           => __( 'Accounting service', 'simple-seo' ),
			'FinancialService'            => __( 'Financial service', 'simple-seo' ),
			'InsuranceAgency'             => __( 'Insurance agency', 'simple-seo' ),
			'RealEstateAgent'             => __( 'Real estate agency', 'simple-seo' ),
			'TravelAgency'                => __( 'Travel agency', 'simple-seo' ),
			'EmploymentAgency'            => __( 'Employment agency', 'simple-seo' ),
			'MedicalClinic'               => __( 'Medical clinic', 'simple-seo' ),
			'Dentist'                     => __( 'Dentist', 'simple-seo' ),
			'Physician'                   => __( 'Physician', 'simple-seo' ),
			'Pharmacy'                    => __( 'Pharmacy', 'simple-seo' ),
			'Optician'                    => __( 'Optician', 'simple-seo' ),
			'HealthAndBeautyBusiness'     => __( 'Health and beauty', 'simple-seo' ),
			'BeautySalon'                 => __( 'Beauty salon', 'simple-seo' ),
			'HairSalon'                   => __( 'Hair salon', 'simple-seo' ),
			'DaySpa'                      => __( 'Spa', 'simple-seo' ),
			'ExerciseGym'                 => __( 'Gym', 'simple-seo' ),
			'FoodEstablishment'           => __( 'Place to eat', 'simple-seo' ),
			'Restaurant'                  => __( 'Restaurant', 'simple-seo' ),
			'CafeOrCoffeeShop'            => __( 'Café or coffee shop', 'simple-seo' ),
			'BarOrPub'                    => __( 'Bar or pub', 'simple-seo' ),
			'Bakery'                      => __( 'Bakery', 'simple-seo' ),
			'LodgingBusiness'             => __( 'Lodging', 'simple-seo' ),
			'Hotel'                       => __( 'Hotel', 'simple-seo' ),
			'Store'                       => __( 'Store', 'simple-seo' ),
			'ClothingStore'               => __( 'Clothing store', 'simple-seo' ),
			'ElectronicsStore'            => __( 'Electronics store', 'simple-seo' ),
			'FurnitureStore'              => __( 'Furniture store', 'simple-seo' ),
			'GroceryStore'                => __( 'Grocery store', 'simple-seo' ),
			'HardwareStore'               => __( 'Hardware store', 'simple-seo' ),
			'AutomotiveBusiness'          => __( 'Automotive business', 'simple-seo' ),
			'AutoDealer'                  => __( 'Car dealer', 'simple-seo' ),
			'AutoRepair'                  => __( 'Car repair', 'simple-seo' ),
			'HomeAndConstructionBusiness' => __( 'Home and construction', 'simple-seo' ),
			'GeneralContractor'           => __( 'General contractor', 'simple-seo' ),
			'Electrician'                 => __( 'Electrician', 'simple-seo' ),
			'Plumber'                     => __( 'Plumber', 'simple-seo' ),
			'HVACBusiness'                => __( 'Heating and air conditioning', 'simple-seo' ),
			'ChildCare'                   => __( 'Child care', 'simple-seo' ),
			'EntertainmentBusiness'       => __( 'Entertainment', 'simple-seo' ),
		);

		$named = array();
		foreach ( array_keys( self::types() ) as $type ) {
			$named[ $type ] = $labels[ $type ] ?? $type;
		}

		return $named;
	}

	/** The type of the organization: the one chosen, while it may be chosen; else Organization. */
	public static function type(): string {
		$type = (string) Simple_SEO_Settings::value( self::OPTION, 'type' );

		return isset( self::types()[ $type ] ) ? $type : 'Organization';
	}

	/** Whether a type is a local business. */
	public static function is_local( string $type ): bool {
		return ! empty( self::types()[ $type ] );
	}

	// ----------------------------------------------------------------------------------------
	// The graph.

	/**
	 * The organization node of the graph with the details of the settings: its type, description,
	 * address, phone, e-mail and area served; for a local business also the coordinates, the map,
	 * the opening hours and the price range. Empty values are left for Simple_SEO_Schema::clean().
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	public static function organization( array $node ): array {
		$settings = Simple_SEO_Settings::get( self::OPTION );
		$names    = Simple_SEO_Settings::get( self::NAMES );
		$type     = self::type();

		$node['@type']       = $type;
		$node['description'] = Simple_SEO_Variables::plain( (string) ( $names['description'] ?? '' ) );
		$node['address']     = self::address( $settings, $names );
		$node['telephone']   = (string) ( $settings['phone'] ?? '' );
		$node['email']       = (string) ( $settings['email'] ?? '' );
		$node['areaServed']  = self::areas( (string) ( $names['area_served'] ?? '' ) );

		if ( self::is_local( $type ) ) {
			$geo = self::coordinates( (string) ( $settings['geo'] ?? '' ) );
			if ( $geo ) {
				$node['geo'] = array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => $geo[0],
					'longitude' => $geo[1],
				);
			}
			$node['hasMap']                    = (string) ( $settings['map'] ?? '' );
			$node['openingHoursSpecification'] = self::hours( is_array( $settings['hours'] ?? null ) ? $settings['hours'] : array() );
			$node['priceRange']                = (string) ( $names['price_range'] ?? '' );
		}

		return $node;
	}

	/**
	 * The postal address; null without any part of it.
	 *
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $names
	 * @return array<string, string>|null
	 */
	private static function address( array $settings, array $names ): ?array {
		$parts = array_filter(
			array(
				'streetAddress'   => (string) ( $names['street'] ?? '' ),
				'addressLocality' => (string) ( $names['locality'] ?? '' ),
				'addressRegion'   => (string) ( $names['region'] ?? '' ),
				'postalCode'      => (string) ( $settings['postal_code'] ?? '' ),
				'addressCountry'  => (string) ( $settings['country'] ?? '' ),
			),
			static fn( string $part ): bool => '' !== $part
		);

		return $parts ? array( '@type' => 'PostalAddress' ) + $parts : null;
	}

	/**
	 * The area served: one place, or a list of the places written with commas between them.
	 *
	 * @return string|string[]
	 */
	private static function areas( string $text ) {
		$areas = array_values( array_filter( array_map( 'trim', explode( ',', $text ) ), 'strlen' ) );

		return 1 === count( $areas ) ? $areas[0] : $areas;
	}

	/**
	 * Latitude and longitude of the stored "50.4501,30.5234"; null for none.
	 *
	 * @return float[]|null
	 */
	public static function coordinates( string $geo ): ?array {
		$parts = explode( ',', $geo );
		if ( 2 !== count( $parts ) || ! is_numeric( $parts[0] ) || ! is_numeric( $parts[1] ) ) {
			return null;
		}

		return array( (float) $parts[0], (float) $parts[1] );
	}

	/**
	 * The opening hours: one specification for the days that open and close at the same time.
	 *
	 * @param array<string, mixed> $hours Day => opens, closes.
	 * @return array<int, array<string, mixed>>
	 */
	public static function hours( array $hours ): array {
		$specifications = array();
		foreach ( self::DAYS as $day => $name ) {
			if ( ! is_array( $hours[ $day ] ?? null ) ) {
				continue;
			}
			$opens  = (string) ( $hours[ $day ]['opens'] ?? '' );
			$closes = (string) ( $hours[ $day ]['closes'] ?? '' );
			$key    = $opens . '-' . $closes;
			if ( ! isset( $specifications[ $key ] ) ) {
				$specifications[ $key ] = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array(),
					'opens'     => $opens,
					'closes'    => $closes,
				);
			}
			$specifications[ $key ]['dayOfWeek'][] = $name;
		}

		return array_values( $specifications );
	}

	// ----------------------------------------------------------------------------------------
	// Settings.

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$type = (string) ( $input['type'] ?? '' );

		return array(
			'type'        => isset( self::types()[ $type ] ) ? $type : 'Organization',
			'phone'       => self::sanitize_phone( $input['phone'] ?? '' ),
			'email'       => self::sanitize_email( $input['email'] ?? '' ),
			'postal_code' => self::text( $input['postal_code'] ?? '', 20 ),
			'country'     => self::sanitize_country( $input['country'] ?? '' ),
			'geo'         => self::sanitize_geo( $input['geo'] ?? '' ),
			'map'         => Simple_SEO_Schema::sanitize_profiles( array( self::text( $input['map'] ?? '', 2000 ) ) )[0] ?? '',
			'hours'       => self::sanitize_hours( $input['hours'] ?? array() ),
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, string>
	 */
	public static function sanitize_names( array $input ): array {
		return array(
			'description' => self::text( $input['description'] ?? '', 500 ),
			'street'      => self::text( $input['street'] ?? '', 200 ),
			'locality'    => self::text( $input['locality'] ?? '', 100 ),
			'region'      => self::text( $input['region'] ?? '', 100 ),
			'area_served' => self::text( $input['area_served'] ?? '', 300 ),
			'price_range' => self::text( $input['price_range'] ?? '', 100 ),
		);
	}

	/**
	 * One line of plain text, at most $length characters.
	 *
	 * @param mixed $value
	 */
	private static function text( $value, int $length ): string {
		return is_scalar( $value ) ? mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, $length ) : '';
	}

	/**
	 * A phone number: digits with +, spaces, dashes, dots and brackets, at least five digits.
	 *
	 * @param mixed $value
	 */
	public static function sanitize_phone( $value ): string {
		$phone = (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^0-9+()\-.\s]/', '', self::text( $value, 40 ) ) );
		$phone = trim( $phone, " -." );

		return preg_match_all( '/\d/', $phone ) >= 5 ? $phone : '';
	}

	/**
	 * An e-mail address; '' for anything that is not one.
	 *
	 * @param mixed $value
	 */
	private static function sanitize_email( $value ): string {
		return sanitize_email( self::text( $value, 100 ) );
	}

	/**
	 * The country: a code of two letters (ISO 3166-1, as search engines prefer it) in capitals, or
	 * the name as written.
	 *
	 * @param mixed $value
	 */
	private static function sanitize_country( $value ): string {
		$country = self::text( $value, 60 );

		return preg_match( '/^[a-z]{2}$/i', $country ) ? strtoupper( $country ) : $country;
	}

	/**
	 * Coordinates as copied from a map — "50.4501, 30.5234", with commas for the decimal point too,
	 * or a space between them — kept as "50.4501,30.5234"; '' for anything else or out of range.
	 *
	 * @param mixed $value
	 */
	public static function sanitize_geo( $value ): string {
		$number = '(-?\d{1,3}(?:[.,]\d+)?)';
		if ( ! preg_match( '/^' . $number . '\s*(?:[,;]\s*|\s+)' . $number . '$/', self::text( $value, 60 ), $match ) ) {
			return '';
		}
		$latitude  = (float) str_replace( ',', '.', $match[1] );
		$longitude = (float) str_replace( ',', '.', $match[2] );
		if ( abs( $latitude ) > 90 || abs( $longitude ) > 180 ) {
			return '';
		}

		$format = static fn( float $number ): string => rtrim( rtrim( number_format( $number, 7, '.', '' ), '0' ), '.' );

		return $format( $latitude ) . ',' . $format( $longitude );
	}

	/**
	 * The opening hours: the days it is open with the time it opens and closes ("09:00"). A day
	 * without either time is open round the clock (00:00–23:59); with only one of them it is left
	 * out. A day closes earlier than it opens when it closes after midnight.
	 *
	 * The form sends open = 0 or 1 for each day; a stored row has only the open days, without it.
	 *
	 * @param mixed $input
	 * @return array<string, array{opens: string, closes: string}>
	 */
	public static function sanitize_hours( $input ): array {
		$hours = array();
		foreach ( array_keys( self::DAYS ) as $day ) {
			$row = is_array( $input ) && is_array( $input[ $day ] ?? null ) ? $input[ $day ] : null;
			if ( null === $row || ( array_key_exists( 'open', $row ) && '1' !== (string) $row['open'] ) ) {
				continue;
			}
			$opens  = self::time( $row['opens'] ?? '' );
			$closes = self::time( $row['closes'] ?? '' );
			if ( '' === $opens && '' === $closes ) {
				$opens  = '00:00';
				$closes = '23:59';
			} elseif ( '' === $opens || '' === $closes ) {
				continue;
			}
			$hours[ $day ] = array(
				'opens'  => $opens,
				'closes' => $closes,
			);
		}

		return $hours;
	}

	/**
	 * A time of day as HH:MM ("9:00" → "09:00", "24:00" → "23:59"); '' for anything else.
	 *
	 * @param mixed $value
	 */
	private static function time( $value ): string {
		if ( ! is_scalar( $value ) || ! preg_match( '/^(\d{1,2})[:.](\d{2})(?::\d{2})?$/', trim( (string) $value ), $match ) ) {
			return '';
		}
		$hour   = (int) $match[1];
		$minute = (int) $match[2];
		if ( 24 === $hour && 0 === $minute ) {
			return '23:59';
		}

		return $hour < 24 && $minute < 60 ? sprintf( '%02d:%02d', $hour, $minute ) : '';
	}

	// ----------------------------------------------------------------------------------------
	// Filling from elsewhere: the imports and the theme.

	/**
	 * Fill the empty fields of a row with values from elsewhere: what the admin has set stays.
	 * A field is empty while it has its default value. With wp-loc a row of another language is
	 * the copy named after it ($code); '' is the default language.
	 *
	 * @param array<string, mixed> $values Field => value, as the form would send it.
	 * @return int How many fields were filled.
	 */
	public static function fill( string $option, string $code, array $values ): int {
		$name     = Simple_SEO_Import_Source::language_row_name( $option, $code );
		$stored   = Simple_SEO_Import_Source::option( $name );
		$clean    = Simple_SEO_Settings::sanitize( $option, is_array( $stored ) ? $stored : array() );
		$new      = Simple_SEO_Settings::sanitize( $option, $values );
		$defaults = Simple_SEO_Settings::defaults( $option );
		$count    = 0;
		foreach ( array_keys( $values ) as $key ) {
			if ( ! array_key_exists( $key, $clean ) || $new[ $key ] === $defaults[ $key ] || $clean[ $key ] !== $defaults[ $key ] ) {
				continue;
			}
			$clean[ $key ] = $new[ $key ];
			++$count;
		}
		if ( $count ) {
			Simple_SEO_Import_Source::save( $name, $clean );
		}

		return $count;
	}

	/**
	 * The theme's details of the organization: WP Base and its forks keep them in the options
	 * schema_org_* (with wp-loc, copies named after the other languages: schema_org_name_en) and
	 * the opening hours in the ACF option opening_hours (Mo… => enabled, opens, closes).
	 *
	 * @return array{local: array<string, mixed>, names: array<string, array<string, string>>, schema: array<string, array<string, string>>}
	 *         The fields of the row of settings, and of the texts and names by language code ('' — the default one).
	 */
	public static function theme_data(): array {
		// The settings' sanitizers make the values plain text.
		$read = static function ( string $name ): string {
			$value = Simple_SEO_Import_Source::option( $name );

			return is_scalar( $value ) ? trim( (string) $value ) : '';
		};

		$local = array_filter(
			array(
				'type'        => $read( 'schema_org_type' ),
				'phone'       => $read( 'schema_org_phone' ) ?: $read( 'options_footer_phone' ),
				'email'       => $read( 'schema_org_email' ) ?: $read( 'options_footer_email' ),
				'postal_code' => $read( 'schema_org_postal_code' ),
				'country'     => $read( 'schema_org_country' ),
			),
			'strlen'
		);
		$hours = array();
		$theme = Simple_SEO_Import_Source::option( 'options_opening_hours' );
		foreach ( is_array( $theme ) ? $theme : array() as $day => $row ) {
			if ( isset( self::DAYS[ $day ] ) && is_array( $row ) && ! empty( $row['enabled'] ) ) {
				$hours[ $day ] = array(
					'opens'  => $row['opens'] ?? '',
					'closes' => $row['closes'] ?? '',
				);
			}
		}
		if ( $hours ) {
			$local['hours'] = $hours;
		}

		$names  = array();
		$schema = array();
		foreach ( Simple_SEO_WP_Loc::codes() as $index => $code ) {
			$code   = 0 === $index ? '' : $code;
			$suffix = '' === $code ? '' : '_' . $code;

			$names[ $code ] = array_filter(
				array(
					'description' => $read( 'schema_org_description' . $suffix ),
					'street'      => $read( 'schema_org_street' . $suffix ),
					'locality'    => $read( 'schema_org_city' . $suffix ),
					'area_served' => $read( 'schema_org_area_served' . $suffix ),
				),
				'strlen'
			);
			$schema[ $code ] = array_filter(
				array(
					'name'      => $read( 'schema_org_name' . $suffix ),
					'alternate' => $read( 'schema_org_alternate_name' . $suffix ) ?: $read( 'schema_org_legal_name' . $suffix ),
				),
				'strlen'
			);
		}

		return array(
			'local'  => $local,
			'names'  => array_filter( $names ),
			'schema' => array_filter( $schema ),
		);
	}

	/** Whether the theme keeps details of the organization. */
	public static function theme_found(): bool {
		$data = self::theme_data();

		return (bool) ( $data['local'] || $data['names'] || $data['schema'] );
	}

	/**
	 * Fill the empty fields of Local SEO and of the names of the Schema tab from the theme, in
	 * every language. The name of the organization is not taken where it is the title of the site.
	 *
	 * @return int How many fields were filled.
	 */
	public static function from_theme(): int {
		$data  = self::theme_data();
		$site  = Simple_SEO_Variables::plain( (string) get_option( 'blogname' ) );
		$count = $data['local'] ? self::fill( self::OPTION, '', $data['local'] ) : 0;
		foreach ( $data['names'] as $code => $names ) {
			$count += self::fill( self::NAMES, (string) $code, $names );
		}
		foreach ( $data['schema'] as $code => $names ) {
			if ( ( $names['name'] ?? '' ) === $site ) {
				unset( $names['name'] );
			}
			if ( $names ) {
				$count += self::fill( Simple_SEO_Schema::NAMES, (string) $code, $names );
			}
		}

		return $count;
	}

	/** The button "Take from the theme"; after it, what was taken. */
	public static function handle(): void {
		if ( ! isset( $_POST['simple_seo_local_theme'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
			$taken = isset( $_GET['simple-seo-theme'] ) ? absint( wp_unslash( $_GET['simple-seo-theme'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message only.
			if ( null !== $taken && 'local' === Simple_SEO_Admin::current_tab() ) {
				add_settings_error(
					'simple-seo-local',
					'simple-seo-theme',
					$taken
						/* translators: %s: number of fields. */
						? sprintf( __( 'Fields taken from the theme: %s.', 'simple-seo' ), number_format_i18n( $taken ) )
						: __( 'Nothing was taken from the theme: the fields it has are filled in already.', 'simple-seo' ),
					$taken ? 'success' : 'info'
				);
				// As core's lists do with their counts: the address loses it, and with it the form's
				// referer field and the address bar (wp_admin_canonical_url()), so a save says it no more.
				if ( isset( $_SERVER['REQUEST_URI'] ) ) {
					$_SERVER['REQUEST_URI'] = remove_query_arg( 'simple-seo-theme', $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- left as WordPress keeps it (slashed), as core's edit.php does.
				}
			}
			return;
		}
		if ( ! current_user_can( Simple_SEO_Access::CAP_SETTINGS ) ) {
			return;
		}
		check_admin_referer( self::NONCE, 'simple_seo_local_nonce' );

		wp_safe_redirect( add_query_arg( 'simple-seo-theme', self::from_theme(), Simple_SEO_Admin::url( 'local' ) ) );
		exit;
	}

	public static function render_tab(): void {
		$settings = Simple_SEO_Settings::get( self::OPTION );
		$names    = Simple_SEO_Settings::get( self::NAMES );
		$language = Simple_SEO_WP_Loc::admin_language();
		$type     = self::type();
		$theme    = self::theme_found();
		$nonce    = self::NONCE;
		$example  = 'organization' === Simple_SEO_Schema::represents() ? Simple_SEO_Schema::publisher() : null;

		require SIMPLE_SEO_DIR . 'includes/views/tab-local.php';
	}
}
