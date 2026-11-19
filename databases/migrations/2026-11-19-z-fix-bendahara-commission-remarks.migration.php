<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    // Older imports stored the commission name on the activity; grouping copied it to each transaction.
    $pdo->exec(
        "UPDATE lpj_bendahara_transaksi_dana t
         JOIN lpj_bendahara_penggunaan_dana u
           ON u.id = t.penggunaan_dana_id
          AND u.lpj_bendahara_id = t.lpj_bendahara_id
         SET t.keterangan = NULL
         WHERE u.source_type = 'komisi'
           AND u.source_activity_key LIKE 'komisi-%'
           AND u.source_activity_key NOT LIKE 'komisi-lpj-%'
           AND u.keterangan IS NOT NULL
           AND t.keterangan IS NOT NULL
           AND BINARY t.keterangan = BINARY u.keterangan"
    );
};
