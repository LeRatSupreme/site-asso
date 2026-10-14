node tests/js/compta-saisie.test.js
if ($LASTEXITCODE -ne 0) { exit 1 }
php -l tests/Unit/PurchaseGridJsTest.php
php -l tests/Unit/BudgetProrataTest.php
php -l tests/Unit/PurchaseTest.php
php -l app/core/Compta/ComptaCalc.php
php -l app/controllers/Admin/AdminBudgetController.php
php -l views/admin/compta/purchases.php
