import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from docx import Document


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scratch" / "bpm_lpj_manager.py"
SPEC = importlib.util.spec_from_file_location("bpm_lpj_manager", SCRIPT)
MANAGER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MANAGER)


class TreasurerGeneratorSmokeTest(unittest.TestCase):
    def test_command_generates_financial_sections(self):
        config = {
            "cover": {"triwulan": "I", "kementerian": "Bendahara Umum"},
            "periode": {"nama": "2026/2027", "tahun_mulai": 2026, "tahun_selesai": 2027},
            "keadaan_objektif": "Kas periode berjalan tertib.",
            "keanggotaan": [{"nama": "Budi", "jabatan": "Bendahara Umum"}],
            "penggunaan_dana": [
                {
                    "tanggal": "2026-01-03",
                    "source_activity_key": "KGT-01",
                    "nama_kegiatan": "Konsumsi",
                    "sumber_dana": "Kas BPM",
                    "transaksi": [
                        {"tanggal": "2026-01-03", "keterangan": "Penerimaan", "uraian": "Dana awal", "debet": 500000, "kredit": 0},
                        {"tanggal": "2026-01-04", "keterangan": "Pembelian", "uraian": "Konsumsi panitia", "debet": 0, "kredit": 125000},
                    ],
                },
                {
                    "tanggal": "2026-01-05",
                    "source_type": "manual",
                    "nama_kegiatan": "Pengeluaran Manual",
                    "sumber_dana": "Kas",
                    "transaksi": [
                        {"tanggal": "2026-01-05", "keterangan": "Pembelian", "uraian": "Perlengkapan", "debet": 0, "kredit": 20000},
                    ],
                },
            ],
            "rekap_kas": [{"tanggal": "2026-01-03", "saldo_awal": 200000, "total_pemasukan": 0, "total_pengeluaran": 125000, "saldo_akhir": 75000}],
            "penutup": "Demikian laporan ini.",
        }
        with tempfile.TemporaryDirectory(prefix="lpj-bendahara-test-") as temp_dir:
            input_path = Path(temp_dir) / "input.json"
            output_path = Path(temp_dir) / "treasurer.docx"
            input_path.write_text(json.dumps(config), encoding="utf-8")
            result = subprocess.run(
                [sys.executable, str(SCRIPT), "generate-treasurer", str(output_path), str(input_path)],
                check=False,
                capture_output=True,
                text=True,
            )
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertTrue(output_path.is_file())
            document = Document(output_path)
            text = "\n".join(paragraph.text for paragraph in document.paragraphs)
            table_text = "\n".join(
                cell.text
                for table in document.tables
                for row in table.rows
                for cell in row.cells
            )
            self.assertIn("BENDAHARA UMUM BPM", text)
            self.assertIn("KEADAAN OBJEKTIF BENDAHARA", text)
            self.assertIn("KEANGGOTAAN", text)
            self.assertIn("LAPORAN PENGGUNAAN DANA", text)
            self.assertIn("Konsumsi", text)
            self.assertIn("Pengeluaran Manual", text)
            self.assertIn("REKAPITULASI KAS", text)
            self.assertGreaterEqual(table_text.count("TOTAL PENGELUARAN"), 2)
            self.assertIn("Konsumsi panitia", table_text)
            self.assertIn("125.000", table_text)
            self.assertIn("375.000", table_text)

    def test_consolidation_orders_treasurer_before_commissions(self):
        input_configs = [
            {"document_type": "commission", "cover": {"triwulan": "I", "kementerian": "Komisi"}},
            {"document_type": "treasurer", "cover": {"triwulan": "I", "kementerian": "Bendahara Umum"}},
            {"document_type": "secretariat", "cover": {"triwulan": "I", "kementerian": "Sekretariat Umum"}},
            {"document_type": "chairperson", "cover": {"triwulan": "I", "kementerian": "Ketua Umum"}},
        ]
        generated = []

        def fake_generate(path, config):
            generated.append(config["document_type"])
            document = Document()
            document.add_paragraph(config["document_type"])
            document.save(path)
            return True

        with tempfile.TemporaryDirectory(prefix="lpj-bendahara-consolidate-") as temp_dir:
            output_path = Path(temp_dir) / "consolidated.docx"
            with patch.object(MANAGER, "generate_lpj", side_effect=fake_generate), \
                 patch.object(MANAGER, "generate_secretariat_lpj", side_effect=fake_generate), \
                 patch.object(MANAGER, "generate_chairperson_lpj", side_effect=fake_generate), \
                 patch.object(MANAGER, "generate_treasurer_lpj", side_effect=fake_generate), \
                 patch.object(MANAGER, "add_consolidated_cover"):
                MANAGER.consolidate_lpj_data(str(output_path), input_configs)
            self.assertEqual(generated, ["chairperson", "secretariat", "treasurer", "commission"])
            self.assertTrue(output_path.is_file())


if __name__ == "__main__":
    unittest.main()
