/**
 * Country name to flag emoji mapping
 * ChronoTrack API returns full country names, not ISO codes
 */

const CountryFlags = {
    // Mapping of country names to flag emojis
    countryToFlag: {
        // Polish names
        'Polska': '🇵🇱',
        'Poland': '🇵🇱',

        // Common countries
        'Germany': '🇩🇪',
        'Niemcy': '🇩🇪',
        'United States': '🇺🇸',
        'USA': '🇺🇸',
        'Stany Zjednoczone': '🇺🇸',
        'United Kingdom': '🇬🇧',
        'UK': '🇬🇧',
        'Wielka Brytania': '🇬🇧',
        'France': '🇫🇷',
        'Francja': '🇫🇷',
        'Italy': '🇮🇹',
        'Włochy': '🇮🇹',
        'Spain': '🇪🇸',
        'Hiszpania': '🇪🇸',
        'Czech Republic': '🇨🇿',
        'Czechy': '🇨🇿',
        'Slovakia': '🇸🇰',
        'Słowacja': '🇸🇰',
        'Ukraine': '🇺🇦',
        'Ukraina': '🇺🇦',
        'Belarus': '🇧🇾',
        'Białoruś': '🇧🇾',
        'Lithuania': '🇱🇹',
        'Litwa': '🇱🇹',
        'Latvia': '🇱🇻',
        'Łotwa': '🇱🇻',
        'Estonia': '🇪🇪',
        'Estonia': '🇪🇪',
        'Russia': '🇷🇺',
        'Rosja': '🇷🇺',
        'Netherlands': '🇳🇱',
        'Holandia': '🇳🇱',
        'Belgium': '🇧🇪',
        'Belgia': '🇧🇪',
        'Switzerland': '🇨🇭',
        'Szwajcaria': '🇨🇭',
        'Austria': '🇦🇹',
        'Austria': '🇦🇹',
        'Hungary': '🇭🇺',
        'Węgry': '🇭🇺',
        'Romania': '🇷🇴',
        'Rumunia': '🇷🇴',
        'Bulgaria': '🇧🇬',
        'Bułgaria': '🇧🇬',
        'Sweden': '🇸🇪',
        'Szwecja': '🇸🇪',
        'Norway': '🇳🇴',
        'Norwegia': '🇳🇴',
        'Denmark': '🇩🇰',
        'Dania': '🇩🇰',
        'Finland': '🇫🇮',
        'Finlandia': '🇫🇮',
        'Portugal': '🇵🇹',
        'Portugalia': '🇵🇹',
        'Greece': '🇬🇷',
        'Grecja': '🇬🇷',
        'Ireland': '🇮🇪',
        'Irlandia': '🇮🇪',
        'Canada': '🇨🇦',
        'Kanada': '🇨🇦',
        'Australia': '🇦🇺',
        'Australia': '🇦🇺',
        'New Zealand': '🇳🇿',
        'Nowa Zelandia': '🇳🇿',
        'Japan': '🇯🇵',
        'Japonia': '🇯🇵',
        'China': '🇨🇳',
        'Chiny': '🇨🇳',
        'South Korea': '🇰🇷',
        'Korea Południowa': '🇰🇷',
        'Brazil': '🇧🇷',
        'Brazylia': '🇧🇷',
        'Argentina': '🇦🇷',
        'Argentyna': '🇦🇷',
        'Mexico': '🇲🇽',
        'Meksyk': '🇲🇽',
        'South Africa': '🇿🇦',
        'RPA': '🇿🇦',
        'Kenya': '🇰🇪',
        'Kenia': '🇰🇪',
        'Ethiopia': '🇪🇹',
        'Etiopia': '🇪🇹',
    },

    /**
     * Get flag emoji for country name
     * @param {string} countryName - Full country name (English or Polish)
     * @returns {string} Flag emoji or empty string if not found
     */
    getFlag: function(countryName) {
        if (!countryName) return '';

        // Try exact match
        if (this.countryToFlag[countryName]) {
            return this.countryToFlag[countryName];
        }

        // Try case-insensitive match
        const lowerCountry = countryName.toLowerCase();
        for (const [name, flag] of Object.entries(this.countryToFlag)) {
            if (name.toLowerCase() === lowerCountry) {
                return flag;
            }
        }

        // No match found
        return '';
    },

    /**
     * Get flag with fallback (shows 🌍 if country not found)
     * @param {string} countryName - Full country name
     * @returns {string} Flag emoji or globe emoji
     */
    getFlagWithFallback: function(countryName) {
        const flag = this.getFlag(countryName);
        return flag || '🌍'; // Globe emoji as fallback
    }
};
