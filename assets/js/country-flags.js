/**
 * Country name to ISO code mapping for flag icons
 * ChronoTrack API returns full country names, not ISO codes
 */

const CountryFlags = {
    // Mapping of country names to ISO 3166-1 alpha-2 codes (lowercase)
    countryToCode: {
        // Polish names
        'Polska': 'pl',
        'Poland': 'pl',

        // Common countries
        'Germany': 'de',
        'Niemcy': 'de',
        'United States': 'us',
        'USA': 'us',
        'Stany Zjednoczone': 'us',
        'United Kingdom': 'gb',
        'UK': 'gb',
        'Wielka Brytania': 'gb',
        'France': 'fr',
        'Francja': 'fr',
        'Italy': 'it',
        'Włochy': 'it',
        'Spain': 'es',
        'Hiszpania': 'es',
        'Czech Republic': 'cz',
        'Czechy': 'cz',
        'Slovakia': 'sk',
        'Słowacja': 'sk',
        'Ukraine': 'ua',
        'Ukraina': 'ua',
        'Belarus': 'by',
        'Białoruś': 'by',
        'Lithuania': 'lt',
        'Litwa': 'lt',
        'Latvia': 'lv',
        'Łotwa': 'lv',
        'Estonia': 'ee',
        'Eesti': 'ee',
        'Russia': 'ru',
        'Rosja': 'ru',
        'Netherlands': 'nl',
        'Holandia': 'nl',
        'Belgium': 'be',
        'Belgia': 'be',
        'Switzerland': 'ch',
        'Szwajcaria': 'ch',
        'Austria': 'at',
        'Hungary': 'hu',
        'Węgry': 'hu',
        'Romania': 'ro',
        'Rumunia': 'ro',
        'Bulgaria': 'bg',
        'Bułgaria': 'bg',
        'Sweden': 'se',
        'Szwecja': 'se',
        'Norway': 'no',
        'Norwegia': 'no',
        'Denmark': 'dk',
        'Dania': 'dk',
        'Finland': 'fi',
        'Finlandia': 'fi',
        'Portugal': 'pt',
        'Portugalia': 'pt',
        'Greece': 'gr',
        'Grecja': 'gr',
        'Ireland': 'ie',
        'Irlandia': 'ie',
        'Canada': 'ca',
        'Kanada': 'ca',
        'Australia': 'au',
        'New Zealand': 'nz',
        'Nowa Zelandia': 'nz',
        'Japan': 'jp',
        'Japonia': 'jp',
        'China': 'cn',
        'Chiny': 'cn',
        'South Korea': 'kr',
        'Korea Południowa': 'kr',
        'Brazil': 'br',
        'Brazylia': 'br',
        'Argentina': 'ar',
        'Argentyna': 'ar',
        'Mexico': 'mx',
        'Meksyk': 'mx',
        'South Africa': 'za',
        'RPA': 'za',
        'Kenya': 'ke',
        'Kenia': 'ke',
        'Ethiopia': 'et',
        'Etiopia': 'et',
    },

    /**
     * Get flag HTML for country name (for HTML display with CSS)
     * @param {string} countryName - Full country name (English or Polish)
     * @returns {string} HTML with flag icon or empty string if not found
     */
    getFlag: function(countryName) {
        if (!countryName) return '';

        const code = this.getCountryCode(countryName);
        if (!code) return '';

        // Return HTML with flag CSS class (for nice SVG flags in HTML)
        return `<span class="country-flag flag-${code}" title="${countryName}"></span>`;
    },

    /**
     * Get flag emoji for country name (for PDF or plain text)
     * @param {string} countryName - Full country name (English or Polish)
     * @returns {string} Flag emoji or empty string if not found
     */
    getFlagEmoji: function(countryName) {
        if (!countryName) return '';

        const code = this.getCountryCode(countryName);
        if (!code) return '';

        // Convert ISO code to flag emoji
        return this.codeToEmoji(code);
    },

    /**
     * Convert ISO country code to flag emoji
     * @param {string} code - Two-letter ISO code (e.g., 'pl', 'de')
     * @returns {string} Flag emoji or empty string
     */
    codeToEmoji: function(code) {
        if (!code || code.length !== 2) return '';

        // Convert to uppercase
        code = code.toUpperCase();

        // Convert to regional indicator symbols
        // A = U+1F1E6, B = U+1F1E7, ..., Z = U+1F1FF
        const firstLetter = code.charCodeAt(0) - 'A'.charCodeAt(0);
        const secondLetter = code.charCodeAt(1) - 'A'.charCodeAt(0);

        if (firstLetter < 0 || firstLetter > 25 || secondLetter < 0 || secondLetter > 25) {
            return '';
        }

        const firstCodepoint = 0x1F1E6 + firstLetter;
        const secondCodepoint = 0x1F1E6 + secondLetter;

        return String.fromCodePoint(firstCodepoint, secondCodepoint);
    },

    /**
     * Get ISO country code for country name
     * @param {string} countryName - Full country name (English or Polish)
     * @returns {string} ISO 3166-1 alpha-2 code (lowercase) or empty string
     */
    getCountryCode: function(countryName) {
        if (!countryName) return '';

        // Try exact match
        if (this.countryToCode[countryName]) {
            return this.countryToCode[countryName];
        }

        // Try case-insensitive match
        const lowerCountry = countryName.toLowerCase();
        for (const [name, code] of Object.entries(this.countryToCode)) {
            if (name.toLowerCase() === lowerCountry) {
                return code;
            }
        }

        // No match found
        return '';
    },

    /**
     * Get flag with fallback (shows globe if country not found)
     * @param {string} countryName - Full country name
     * @returns {string} HTML with flag icon or globe emoji
     */
    getFlagWithFallback: function(countryName) {
        const flag = this.getFlag(countryName);
        if (flag) return flag;

        // Fallback: globe emoji
        return '<span class="flag-unknown" title="Unknown country">🌍</span>';
    }
};
