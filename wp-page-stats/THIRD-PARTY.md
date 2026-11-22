# Country database attribution

The files in `data/` are derived from **DB-IP IP to Country Lite, November 2026**.

- Source: https://download.db-ip.com/free/dbip-country-lite-2026-11.csv.gz
- Provider: https://db-ip.com/
- Dataset description and terms: https://db-ip.com/db/lite.php
- Dataset license: Creative Commons Attribution 4.0 International (CC BY 4.0)
- License text: https://creativecommons.org/licenses/by/4.0/legalcode
- Required attribution: **IP Geolocation by DB-IP**, linked to https://db-ip.com/

The original CSV was converted into two sorted binary lookup tables. IPv4 records
contain a 4-byte start, 4-byte end and 2-byte country code; IPv6 records contain a
16-byte start, 16-byte end and 2-byte country code. Integers use network byte order.
No country assignments were changed. The packaged dataset contains 732,796 ranges.
The license of the data is separate from the GPL license of the plugin's code.
Attribution is displayed on all plugin report/settings pages that use country data.

DB-IP Lite has reduced coverage and accuracy compared with the commercial dataset.
It describes the approximate country associated with an address range; this does
not establish the physical location or nationality of a visitor.
