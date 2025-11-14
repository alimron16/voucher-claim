<?php
date_default_timezone_set('Asia/Jakarta');

$file = __DIR__ . "/vouchers.json";

// Init file jika belum ada
if (!file_exists($file)) {
    file_put_contents($file, json_encode([]), LOCK_EX);
}

// Load data dengan safety
function loadData($file) {
    $json = @file_get_contents($file);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

// Simpan data dengan LOCK_EX
function saveData($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

// Generate next numeric ID
function nextId(&$data) {
    $max = 0;
    foreach ($data as $d) {
        if (isset($d['id']) && is_numeric($d['id']) && $d['id'] > $max) $max = (int)$d['id'];
    }
    return $max + 1;
}

$vouchers = loadData($file);

// === HANDLE UPLOAD ===
if (isset($_POST['upload'])) {
    $now = date("Y-m-d H:i:s");
    $changed = false;

    // === tambahan kode_produk ===
    $kode_produk = trim($_POST['kode_produk'] ?? '');

    // satuan
    if (!empty($_POST['no_vc'])) {
        $id = nextId($vouchers);
        $vouchers[] = [
            "id" => $id,
            "kode_produk" => $kode_produk, // === tambahan kode_produk ===
            "no_vc" => trim($_POST['no_vc']),
            "status" => "aktif",
            "tgl_upload" => $now,
            "tgl_update" => $now
        ];
        $changed = true;
    }

    // bulk
    if (!empty($_POST['bulk_vc'])) {
        $bulk = preg_split("/\r\n|\n|\r/", $_POST['bulk_vc']);
        foreach ($bulk as $line) {
            $no_vc = trim($line);
            if ($no_vc !== "") {
                $id = nextId($vouchers);
                $vouchers[] = [
                    "id" => $id,
                    "kode_produk" => $kode_produk, // === tambahan kode_produk ===
                    "no_vc" => $no_vc,
                    "status" => "aktif",
                    "tgl_upload" => $now,
                    "tgl_update" => $now
                ];
                $changed = true;
            }
        }
    }

    if ($changed) {
        saveData($file, $vouchers);
    }

    header("Location: voucher.php");
    exit;
}

// === HANDLE EDIT STATUS ===
if (isset($_POST['edit_status']) && isset($_POST['id']) && isset($_POST['status'])) {
    $id = (int)$_POST['id'];
    $newStatus = $_POST['status'] === 'aktif' ? 'aktif' : 'nonaktif';
    foreach ($vouchers as &$v) {
        if ((int)$v['id'] === $id) {
            $v['status'] = $newStatus;
            $v['tgl_update'] = date("Y-m-d H:i:s");
            break;
        }
    }
    saveData($file, $vouchers);
    header("Location: voucher.php");
    exit;
}

// === HANDLE HAPUS VOUCHER ===
if (isset($_POST['hapus']) && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $vouchers = array_values(array_filter($vouchers, fn($v) => (int)$v['id'] !== $id));
    saveData($file, $vouchers);
    header("Location: voucher.php");
    exit;
}

// === HANDLE REQUEST VOUCHER (API) ===
if (isset($_GET['action']) && $_GET['action'] === "request") {
    $trxid = isset($_GET['trxid']) ? trim($_GET['trxid']) : null;
    $kode_produk_req = isset($_GET['kode_produk']) ? trim($_GET['kode_produk']) : null; // === tambahan kode_produk ===

    $aktifIndexes = [];
    foreach ($vouchers as $k => $v) {
        if (
            isset($v['status']) && $v['status'] === 'aktif' &&
            (!$kode_produk_req || (isset($v['kode_produk']) && $v['kode_produk'] === $kode_produk_req))
        ) {
            $aktifIndexes[] = $k;
        }
    }

    header('Content-Type: application/json; charset=utf-8');

    if (count($aktifIndexes) > 0) {
        $randIndex = $aktifIndexes[array_rand($aktifIndexes)];
        $voucher = $vouchers[$randIndex];

        // ubah status jadi nonaktif
        $vouchers[$randIndex]['status'] = 'nonaktif';
        $vouchers[$randIndex]['tgl_update'] = date("Y-m-d H:i:s");
        saveData($file, $vouchers);

        $response = [
            "status" => "success",
            "id" => $voucher['id'],
            "kode_produk" => $voucher['kode_produk'], // === tambahan kode_produk ===
            "voucher" => $voucher['no_vc']
        ];
        if ($trxid) {
            $response["trxid"] = $trxid;
        }

        echo json_encode($response, JSON_PRETTY_PRINT);
    } else {
        $response = [
            "status" => "error",
            "message" => $kode_produk_req ? 
                "Tidak ada voucher aktif untuk kode_produk {$kode_produk_req}" : 
                "Tidak ada voucher aktif"
        ];
        if ($trxid) {
            $response["trxid"] = $trxid;
        }
        echo json_encode($response, JSON_PRETTY_PRINT);
    }
    exit;
}

// reload data
$vouchers = loadData($file);

// === FILTER SEARCH ===
$filter_vc = $_GET['vc'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_tgl = $_GET['tgl'] ?? '';
$filter_kode = $_GET['kode_produk'] ?? ''; // === tambahan kode_produk ===

$filtered = array_filter($vouchers, function ($v) use ($filter_vc, $filter_status, $filter_tgl, $filter_kode) {
    $ok = true;
    if ($filter_vc !== '' && stripos($v['no_vc'], $filter_vc) === false) $ok = false;
    if ($filter_status !== '' && $v['status'] !== $filter_status) $ok = false;
    if ($filter_tgl !== '' && strpos($v['tgl_upload'], $filter_tgl) !== 0) $ok = false;
    if ($filter_kode !== '' && ($v['kode_produk'] ?? '') !== $filter_kode) $ok = false;
    return $ok;
});
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title>Voucher (JSON storage)</title>
</head>
<body>
    <h2>Upload Voucher</h2>
    <form method="post">
        Kode Produk: <input type="text" name="kode_produk" required> <!-- tambahan kode_produk -->
        <br><br>
        Nomor VC (satuan): <input type="text" name="no_vc" autocomplete="off">
        <button type="submit" name="upload">Upload Satuan</button>
        <br><br>
        Bulk VC (pisahkan baris): <br>
        <textarea name="bulk_vc" rows="6" cols="60"></textarea><br>
        <button type="submit" name="upload">Upload Bulk</button>
    </form>

    <h2>Filter Voucher</h2>
    <form method="get">
        Nomor VC: <input type="text" name="vc" value="<?= htmlspecialchars($filter_vc) ?>">
        Kode Produk: <input type="text" name="kode_produk" value="<?= htmlspecialchars($filter_kode) ?>"> <!-- tambahan kode_produk -->
        Status: 
        <select name="status">
            <option value="">-- Semua --</option>
            <option value="aktif" <?= $filter_status === 'aktif' ? 'selected' : '' ?>>aktif</option>
            <option value="nonaktif" <?= $filter_status === 'nonaktif' ? 'selected' : '' ?>>nonaktif</option>
        </select>
        Tanggal Upload: <input type="date" name="tgl" value="<?= htmlspecialchars($filter_tgl) ?>">
        <button type="submit">Cari</button>
    </form>

    <h2>Daftar Voucher</h2>
    <table border="1" cellpadding="6" cellspacing="0">
        <tr>
            <th>ID</th>
            <th>Kode Produk</th> <!-- tambahan kode_produk -->
            <th>No VC</th>
            <th>Status</th>
            <th>Tgl Upload</th>
            <th>Tgl Update</th>
            <th>Aksi</th>
        </tr>
        <?php if (empty($filtered)): ?>
            <tr><td colspan="7">Tidak ada data.</td></tr>
        <?php else: ?>
            <?php foreach ($filtered as $v): ?>
                <tr>
                    <td><?= htmlspecialchars($v['id']) ?></td>
                    <td><?= htmlspecialchars($v['kode_produk'] ?? '') ?></td>
                    <td><?= htmlspecialchars($v['no_vc']) ?></td>
                    <td><?= htmlspecialchars($v['status']) ?></td>
                    <td><?= htmlspecialchars($v['tgl_upload']) ?></td>
                    <td><?= htmlspecialchars($v['tgl_update']) ?></td>
                    <td>
                        <!-- Edit Status -->
                        <form method="post" style="display:inline">
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <select name="status">
                                <option value="aktif" <?= ($v['status'] === 'aktif') ? 'selected' : '' ?>>aktif</option>
                                <option value="nonaktif" <?= ($v['status'] === 'nonaktif') ? 'selected' : '' ?>>nonaktif</option>
                            </select>
                            <button type="submit" name="edit_status">Simpan</button>
                        </form>
                        <!-- Hapus -->
                        <form method="post" style="display:inline" onsubmit="return confirm('Yakin hapus voucher ini?')">
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <button type="submit" name="hapus">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>

    <h2>Request Voucher (API)</h2>
    <p>Panggil URL: 
        <code>voucher.php?action=request&trxid=ABC123&kode_produk=ML001</code>  
        untuk mendapatkan 1 voucher aktif random sesuai kode_produk (JSON).  
        Jika tidak pakai <b>kode_produk</b>, akan ambil voucher aktif dari semua produk.<br>
        Voucher akan otomatis jadi <b>nonaktif</b> setelah diambil.
    </p>
</body>
</html>
