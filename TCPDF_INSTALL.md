# TCPDF Installation Instructions

## Błąd: "TCPDF library not found"

Jeśli widzisz błąd **"TCPDF library not found. Please install TCPDF."** przy generowaniu PDF, oznacza to że biblioteka TCPDF nie jest zainstalowana.

## Instalacja TCPDF

### Metoda 1: Automatyczna instalacja (zalecana)

1. Połącz się z serwerem przez SSH
2. Przejdź do katalogu wtyczki:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/chronotrack-live-results
   ```
3. Uruchom skrypt instalacyjny:
   ```bash
   bash install-tcpdf.sh
   ```

Skrypt automatycznie:
- Pobierze TCPDF 6.7.5 z GitHub
- Wypakuje do katalogu `lib/tcpdf/`
- Sprawdzi czy instalacja się powiodła

### Metoda 2: Ręczna instalacja

1. Pobierz TCPDF z GitHub:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/chronotrack-live-results
   mkdir -p lib
   cd lib
   wget https://github.com/tecnickcom/TCPDF/archive/refs/tags/6.7.5.tar.gz
   tar -xzf 6.7.5.tar.gz
   mv TCPDF-6.7.5 tcpdf
   rm 6.7.5.tar.gz
   ```

2. Sprawdź czy plik główny istnieje:
   ```bash
   ls -la lib/tcpdf/tcpdf.php
   ```

   Powinieneś zobaczyć:
   ```
   -rw-r--r-- 1 user user 123456 ... lib/tcpdf/tcpdf.php
   ```

### Metoda 3: Używając Composera (dla zaawansowanych)

1. Dodaj do `composer.json`:
   ```json
   {
       "require": {
           "tecnickcom/tcpdf": "^6.7"
       }
   }
   ```

2. Zainstaluj:
   ```bash
   composer install
   ```

3. Skopiuj TCPDF do katalogu `lib/`:
   ```bash
   mkdir -p lib
   cp -r vendor/tecnickcom/tcpdf lib/
   ```

## Sprawdzenie instalacji

Po instalacji sprawdź czy TCPDF działa:

1. Przejdź do panelu administracyjnego WordPress
2. ChronoTrack Live Results → Wydarzenia
3. Wybierz wydarzenie → "Pobierz dane"
4. Kliknij "Generuj PDF" dla dowolnego dystansu
5. PDF powinien się wygenerować bez błędów

## Struktura katalogów

Po instalacji struktura powinna wyglądać tak:

```
chronotrack-live-results/
├── chronotrack-live-results.php
├── includes/
│   ├── class-chronotrack-pdf-generator.php
│   └── ...
├── lib/
│   └── tcpdf/
│       ├── tcpdf.php          <- Główny plik TCPDF
│       ├── include/
│       ├── fonts/
│       └── ...
└── install-tcpdf.sh
```

## Rozwiązywanie problemów

### Błąd: "Permission denied"

Jeśli widzisz błąd uprawnień:
```bash
chmod +x install-tcpdf.sh
bash install-tcpdf.sh
```

### Błąd: "wget: command not found"

Zainstaluj wget lub curl:
```bash
# Debian/Ubuntu
sudo apt-get install wget

# CentOS/RHEL
sudo yum install wget
```

### Błąd: TCPDF nadal nie działa

1. Sprawdź uprawnienia:
   ```bash
   ls -la lib/tcpdf/tcpdf.php
   ```

2. Upewnij się że plik istnieje:
   ```bash
   cat lib/tcpdf/tcpdf.php | head -5
   ```

   Powinieneś zobaczyć:
   ```php
   <?php
   //============================================================+
   // File name   : tcpdf.php
   ```

3. Sprawdź logi WordPress (wp-content/debug.log) dla szczegółowych błędów

## Wymagania systemowe

- PHP 7.4 lub nowszy
- Rozszerzenia PHP:
  - gd lub imagick (dla obrazów)
  - mbstring (dla UTF-8)
  - zlib (dla kompresji PDF)

Sprawdź czy rozszerzenia są zainstalowane:
```bash
php -m | grep -E "gd|imagick|mbstring|zlib"
```

## Wsparcie

Jeśli problemy z instalacją TCPDF dalej występują:
1. Sprawdź logi błędów WordPress
2. Sprawdź logi serwera (Apache/Nginx)
3. Skontaktuj się z administratorem serwera
