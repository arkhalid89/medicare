using System.Globalization;
using System.Text;
using Dapper;
using MediCare.Contracts;
using MediCare.Core;
using MediCare.Host.Domain;
using MediCare.Host.Infrastructure;
using MediCare.Host.Security;
using Microsoft.Data.Sqlite;
using MiniExcelLibs;

namespace MediCare.Host.Modules;

public static class CatalogueModule
{
    public static void Map(WebApplication app)
    {
        var g = app.MapGroup("/api/v1");
        g.MapGet("/medicines", (string? q, bool? includeInactive, int? limit, CatalogueService s) => s.Search(q, includeInactive ?? false, limit ?? 100)).Authenticated();
        g.MapGet("/medicines/{id:long}", (long id, CatalogueService s) => s.Get(id)).Authenticated();
        g.MapGet("/medicines/{id:long}/batches", (long id, CatalogueService s) => s.Batches(id, includeEmpty: false)).Authenticated();
        g.MapGet("/medicines/scan", (string code, CatalogueService s) => s.Scan(code)).Authenticated();
        g.MapPost("/medicines", (HttpContext http, MedicineDto m, CatalogueService s) => s.Save(http.Ctx(), null, m)).Perm(Perm.MedicinesEdit);
        g.MapPut("/medicines/{id:long}", (long id, HttpContext http, MedicineDto m, CatalogueService s) => s.Save(http.Ctx(), id, m)).Perm(Perm.MedicinesEdit);

        g.MapPost("/imports/medicines", async (HttpRequest req, HttpContext http, ImportService s) =>
        {
            if (!req.HasFormContentType) throw AppException.Validation("Upload the file as multipart form data.");
            var form = await req.ReadFormAsync();
            var file = form.Files.FirstOrDefault() ?? throw AppException.Validation("Choose a file to import.");
            using var ms = new MemoryStream();
            await file.CopyToAsync(ms);
            return s.Upload(http.Ctx(), file.FileName, ms.ToArray());
        }).Perm(Perm.MedicinesImport).DisableAntiforgery();
        g.MapGet("/imports/{id:long}", (long id, ImportService s) => s.Preview(id)).Perm(Perm.MedicinesImport);
        g.MapPut("/imports/{id:long}/mapping", (long id, ImportMapRequest r, ImportService s) => s.Map(id, r.Mapping)).Perm(Perm.MedicinesImport);
        g.MapPost("/imports/{id:long}/commit", (long id, bool? dryRun, HttpContext http, ImportService s) => s.Commit(http.Ctx(), id, dryRun ?? false)).Perm(Perm.MedicinesImport);
        g.MapPost("/imports/{id:long}/undo", (long id, HttpContext http, ImportService s) => s.Undo(http.Ctx(), id)).Perm(Perm.MedicinesImport);
        g.MapGet("/imports/{id:long}/errors.csv", (long id, ImportService s) => Results.Text(s.ErrorsCsv(id), "text/csv", Encoding.UTF8)).Perm(Perm.MedicinesImport);
    }
}

public sealed class CatalogueService(Db db, SettingsService settings)
{
    internal sealed class MedRow
    {
        public long Id { get; set; }
        public string Code { get; set; } = "";
        public string Name { get; set; } = "";
        public string Generic { get; set; } = "";
        public string Brand { get; set; } = "";
        public string Manufacturer { get; set; } = "";
        public string Category { get; set; } = "";
        public string DosageForm { get; set; } = "";
        public string Strength { get; set; } = "";
        public string BaseUnit { get; set; } = "";
        public long? DefaultSalePackId { get; set; }
        public long? PurchasePackId { get; set; }
        public long MinStockQty { get; set; }
        public long TaxBp { get; set; }
        public long RxRequired { get; set; }
        public string Schedule { get; set; } = "";
        public string UrduName { get; set; } = "";
        public string Rack { get; set; } = "";
        public long DefaultPurchaseE4 { get; set; }
        public long IsActive { get; set; }
    }

    internal sealed class PackRow
    {
        public long Id { get; set; }
        public long MedicineId { get; set; }
        public string Name { get; set; } = "";
        public long BaseQty { get; set; }
        public long IsSellable { get; set; }
        public long SalePaisa { get; set; }
        public long IsActive { get; set; }
    }

    internal static MedRow Row(SqliteConnection c, SqliteTransaction? tx, long id) =>
        c.QuerySingleOrDefault<MedRow>("SELECT * FROM medicines WHERE id=@id", new { id }, tx) ?? throw AppException.NotFound("Medicine");

    internal static List<PackRow> Packs(SqliteConnection c, SqliteTransaction? tx, long medicineId) =>
        c.Query<PackRow>("SELECT * FROM medicine_packs WHERE medicine_id=@medicineId ORDER BY base_qty DESC", new { medicineId }, tx).ToList();

    internal static string QtyText(int baseQty, IEnumerable<PackRow> packs, string baseUnit) =>
        QtyFormat.Format(baseQty, packs.Where(p => p.IsActive == 1).Select(p => new PackSize(p.Name, (int)p.BaseQty)), baseUnit);

    internal static int SaleableQty(SqliteConnection c, SqliteTransaction? tx, long medicineId) =>
        c.ExecuteScalar<int>(@"SELECT IFNULL(SUM(sb.qty),0) FROM stock_balances sb JOIN batches b ON b.id=sb.batch_id
                               WHERE b.medicine_id=@medicineId AND sb.state='SALEABLE' AND b.expiry_date > @today", new { medicineId, today = Clock.BusinessDate() }, tx);

    public MedicineDto Get(long id) => db.Read(c => ToDto(c, null, id));

    internal static MedicineDto ToDto(SqliteConnection c, SqliteTransaction? tx, long id)
    {
        var m = Row(c, tx, id);
        var packs = Packs(c, tx, id);
        var (avg, _) = StockLedger.Cost(c, tx, id);
        return new MedicineDto
        {
            Id = m.Id, Code = m.Code, Name = m.Name, Generic = m.Generic, Brand = m.Brand, Manufacturer = m.Manufacturer, Category = m.Category,
            DosageForm = m.DosageForm, Strength = m.Strength, BaseUnit = m.BaseUnit,
            Packs = packs.Where(p => p.IsActive == 1).Select(p => new PackDto { Id = p.Id, Name = p.Name, BaseQty = (int)p.BaseQty, IsSellable = p.IsSellable == 1, SalePrice = Money.FromPaisa(p.SalePaisa) }).ToList(),
            Barcodes = c.Query<(long id, string code, string? pack)>("SELECT b.id, b.code, p.name FROM barcodes b LEFT JOIN medicine_packs p ON p.id=b.pack_id WHERE b.medicine_id=@id ORDER BY b.id", new { id }, tx)
                .Select(b => new BarcodeDto { Id = b.id, Code = b.code, PackName = b.pack }).ToList(),
            DefaultSalePack = packs.FirstOrDefault(p => p.Id == m.DefaultSalePackId)?.Name ?? "",
            PurchasePack = packs.FirstOrDefault(p => p.Id == m.PurchasePackId)?.Name ?? "",
            MinStockQty = (int)m.MinStockQty, TaxRatePercent = Money.BpToPercent(m.TaxBp), RxRequired = m.RxRequired == 1, Schedule = m.Schedule, UrduName = m.UrduName,
            Rack = m.Rack, IsActive = m.IsActive == 1, DefaultPurchaseCost = Money.FromE4(m.DefaultPurchaseE4),
            StockQty = SaleableQty(c, tx, id),
            QuarantineQty = c.ExecuteScalar<int>("SELECT IFNULL(SUM(sb.qty),0) FROM stock_balances sb JOIN batches b ON b.id=sb.batch_id WHERE b.medicine_id=@id AND sb.state='QUARANTINE'", new { id }, tx),
            AvgCost = Money.FromE4(avg),
        };
    }

    public List<MedicineListItem> Search(string? q, bool includeInactive, int limit)
    {
        limit = Math.Clamp(limit, 1, 500);
        return db.Read(c =>
        {
            var ids = new List<long>();
            var text = (q ?? "").Trim();
            if (text.Length == 0)
                ids = c.Query<long>("SELECT id FROM medicines WHERE (@all=1 OR is_active=1) ORDER BY name LIMIT @limit", new { all = includeInactive ? 1 : 0, limit }).ToList();
            else
            {
                var exact = c.Query<long>("SELECT medicine_id FROM barcodes WHERE code=@text UNION SELECT id FROM medicines WHERE code=@text COLLATE NOCASE", new { text }).ToList();
                ids.AddRange(exact);
                var tokens = text.Split(' ', StringSplitOptions.RemoveEmptyEntries).Select(t => new string(t.Where(ch => char.IsLetterOrDigit(ch)).ToArray())).Where(t => t.Length > 0).ToList();
                if (tokens.Count > 0)
                {
                    var match = string.Join(" ", tokens.Select(t => "\"" + t + "\"*"));
                    ids.AddRange(c.Query<long>(@"SELECT f.rowid FROM medicine_fts f JOIN medicines m ON m.id=f.rowid
                        WHERE medicine_fts MATCH @match AND (@all=1 OR m.is_active=1) ORDER BY m.name LIMIT @limit", new { match, all = includeInactive ? 1 : 0, limit }));
                }
                if (ids.Count < limit && text.Length >= 3)
                    ids.AddRange(c.Query<long>("SELECT id FROM medicines WHERE (name LIKE '%'||@text||'%' OR generic LIKE '%'||@text||'%') AND (@all=1 OR is_active=1) ORDER BY name LIMIT @limit",
                        new { text, all = includeInactive ? 1 : 0, limit }));
                ids = ids.Distinct().Take(limit).ToList();
            }
            if (ids.Count == 0) return new List<MedicineListItem>();
            var today = Clock.BusinessDate();
            var meds = c.Query<MedRow>("SELECT * FROM medicines WHERE id IN @ids", new { ids }).ToDictionary(m => m.Id);
            var packs = c.Query<PackRow>("SELECT * FROM medicine_packs WHERE medicine_id IN @ids", new { ids }).GroupBy(p => p.MedicineId).ToDictionary(g => g.Key, g => g.ToList());
            var stock = c.Query<(long mid, long qty)>(@"SELECT b.medicine_id, SUM(sb.qty) FROM stock_balances sb JOIN batches b ON b.id=sb.batch_id
                WHERE b.medicine_id IN @ids AND sb.state='SALEABLE' AND b.expiry_date > @today GROUP BY b.medicine_id", new { ids, today }).ToDictionary(x => x.mid, x => x.qty);
            return ids.Where(meds.ContainsKey).Select(id =>
            {
                var m = meds[id];
                var ps = packs.TryGetValue(id, out var l) ? l : new List<PackRow>();
                var def = ps.FirstOrDefault(p => p.Id == m.DefaultSalePackId) ?? ps.FirstOrDefault();
                var qty = (int)(stock.TryGetValue(id, out var s) ? s : 0);
                return new MedicineListItem
                {
                    Id = id, Code = m.Code, Name = m.Name, Generic = m.Generic, Manufacturer = m.Manufacturer, DosageForm = m.DosageForm, Strength = m.Strength,
                    DefaultPack = def?.Name ?? m.BaseUnit, SalePrice = Money.FromPaisa(def?.SalePaisa ?? 0), StockQty = qty,
                    StockText = def == null ? qty.ToString(CultureInfo.InvariantCulture) : QtyFormat.InPack(qty, new PackSize(def.Name, (int)def.BaseQty), m.BaseUnit),
                    MinStockQty = (int)m.MinStockQty, IsActive = m.IsActive == 1, Schedule = m.Schedule,
                };
            }).ToList();
        });
    }

    public List<BatchAvailDto> Batches(long medicineId, bool includeEmpty) => db.Read(c => BatchesOf(c, null, medicineId, includeEmpty));

    internal static List<BatchAvailDto> BatchesOf(SqliteConnection c, SqliteTransaction? tx, long medicineId, bool includeEmpty)
    {
        var today = Clock.Today;
        return c.Query<(long id, string batch_no, string expiry_date, string rack, long? sale_paisa, long saleable, long quarantine)>(
                @"SELECT b.id, b.batch_no, b.expiry_date, b.rack, b.sale_paisa,
                    IFNULL((SELECT qty FROM stock_balances WHERE batch_id=b.id AND state='SALEABLE'),0),
                    IFNULL((SELECT qty FROM stock_balances WHERE batch_id=b.id AND state='QUARANTINE'),0)
                  FROM batches b WHERE b.medicine_id=@medicineId ORDER BY b.expiry_date, b.id", new { medicineId }, tx)
            .Where(b => includeEmpty || b.saleable > 0 || b.quarantine > 0)
            .Select(b =>
            {
                var exp = Clock.ParseDate(b.expiry_date);
                var saleable = Fefo.IsSaleable(exp, today);
                return new BatchAvailDto
                {
                    BatchId = b.id, BatchNo = b.batch_no, Expiry = exp, DaysLeft = Fefo.DaysLeft(exp, today), Rack = b.rack, AvailableQty = (int)b.saleable,
                    Saleable = saleable && b.saleable > 0, State = !saleable ? "EXPIRED" : b.saleable > 0 ? "SALEABLE" : "QUARANTINE",
                    SalePrice = b.sale_paisa == null ? null : Money.FromPaisa(b.sale_paisa.Value),
                };
            }).ToList();
    }

    public ScanResult Scan(string code)
    {
        code = (code ?? "").Trim();
        if (code.Length == 0) throw AppException.Validation("Scan or type a barcode.");
        return db.Read(c =>
        {
            Gs1Result? gs1 = null;
            (long medicineId, long? packId)? hit = null;
            if (Gs1Parser.IsCandidate(code))
            {
                gs1 = Gs1Parser.Parse(code);
                if (!gs1.Ok) throw new AppException(400, gs1.Error ?? ErrorCodes.NotGs1, gs1.ErrorMessage ?? "Invalid GS1 code.");
                foreach (var v in Gtin.Variants(gs1.Gtin))
                {
                    hit = c.QueryOne<(long, long?)>("SELECT medicine_id, pack_id FROM barcodes WHERE code=@v", new { v });
                    if (hit != null) break;
                }
                if (hit == null) throw new AppException(404, ErrorCodes.NotFound, $"Product code {gs1.Gtin} from the 2D code is not linked to any medicine. Add it as a barcode in the medicine editor.");
            }
            else
            {
                hit = c.QueryOne<(long, long?)>("SELECT medicine_id, pack_id FROM barcodes WHERE code=@code", new { code })
                      ?? c.QueryOne<(long, long?)>("SELECT id, NULL FROM medicines WHERE code=@code COLLATE NOCASE", new { code });
                if (hit == null) throw new AppException(404, ErrorCodes.NotFound, $"Barcode {code} was not found. Press F2 to search by name, or ask a manager to add the barcode.");
            }
            var med = ToDto(c, null, hit.Value.medicineId);
            var batches = BatchesOf(c, null, med.Id, includeEmpty: true);
            var result = new ScanResult { Medicine = med, PackId = hit.Value.packId, Batches = batches.Where(b => b.AvailableQty > 0 || b.State == "QUARANTINE").ToList() };
            if (gs1 != null)
            {
                result.Gs1 = new Gs1Info { Gtin = gs1.Gtin, Batch = gs1.Batch, Expiry = gs1.Expiry, Manufactured = gs1.Manufactured, Serial = gs1.Serial };
                if (!string.IsNullOrEmpty(gs1.Batch))
                {
                    var b = batches.FirstOrDefault(x => x.BatchNo.Equals(gs1.Batch, StringComparison.OrdinalIgnoreCase));
                    if (b == null) result.Warning = $"Batch {gs1.Batch} from the 2D code is not in stock. Earliest-expiry batch will be used.";
                    else if (b.State == "EXPIRED") result.Warning = $"Batch {b.BatchNo} expired on {Dates.Display(b.Expiry)}. It cannot be sold. Move it to quarantine.";
                    else if (!b.Saleable) result.Warning = $"Batch {b.BatchNo} has no saleable stock. Earliest-expiry batch will be used.";
                    else result.ScannedBatchId = b.BatchId;
                }
            }
            return result;
        });
    }

    public OkResult Save(Ctx ctx, long? id, MedicineDto m)
    {
        if (string.IsNullOrWhiteSpace(m.Name)) throw AppException.Validation("Medicine name is required.");
        if (string.IsNullOrWhiteSpace(m.BaseUnit)) throw AppException.Validation("Base unit is required (e.g. Tablet, Capsule, Bottle).");
        if (m.TaxRatePercent < 0 || m.TaxRatePercent > 100) throw AppException.Validation("Tax rate must be between 0 and 100%.");
        if (m.MinStockQty < 0) throw AppException.Validation("Minimum stock cannot be negative.");
        if (!new[] { "", "B", "D", "G" }.Contains(m.Schedule ?? "")) throw AppException.Validation("Schedule must be empty, B, D or G.");
        if (m.Schedule == "G" && settings.Get("pharmacy.licenceType") == "MEDICAL_STORE") throw AppException.Validation("A medical store licence cannot stock Schedule G medicines.");
        var packs = m.Packs.Where(p => !string.IsNullOrWhiteSpace(p.Name)).ToList();
        if (packs.Count == 0) throw AppException.Validation("Add at least one pack level (e.g. Strip of 10).");
        if (packs.Any(p => p.BaseQty < 1)) throw AppException.Validation("Every pack must contain at least 1 base unit.");
        if (packs.Any(p => p.SalePrice < 0)) throw AppException.Validation("Sale prices cannot be negative.");
        if (packs.GroupBy(p => p.Name.Trim(), StringComparer.OrdinalIgnoreCase).Any(g => g.Count() > 1)) throw AppException.Validation("Pack names must be different.");
        if (!packs.Any(p => p.BaseQty == 1)) packs.Add(new PackDto { Name = m.BaseUnit.Trim(), BaseQty = 1, IsSellable = false, SalePrice = 0 });
        if (!packs.Any(p => p.IsSellable)) throw AppException.Validation("At least one pack must be sellable.");
        var defaultSale = packs.FirstOrDefault(p => p.Name.Equals(m.DefaultSalePack, StringComparison.OrdinalIgnoreCase) && p.IsSellable) ?? packs.Where(p => p.IsSellable).OrderBy(p => p.BaseQty == 1 ? 1 : 0).ThenBy(p => p.BaseQty).First();
        var purchase = packs.FirstOrDefault(p => p.Name.Equals(m.PurchasePack, StringComparison.OrdinalIgnoreCase)) ?? packs.OrderByDescending(p => p.BaseQty).First();
        var barcodes = m.Barcodes.Select(b => b.Code.Trim()).Where(b => b.Length > 0).Distinct().ToList();
        foreach (var b in barcodes)
            if (b.Length > 40) throw AppException.Validation($"Barcode {b} is too long.");

        var newId = db.Write((c, tx) =>
        {
            foreach (var b in barcodes)
            {
                var owner = c.QueryOne<(long mid, string name)>("SELECT m.id, m.name FROM barcodes b JOIN medicines m ON m.id=b.medicine_id WHERE b.code=@b", new { b }, tx);
                if (owner != null && owner.Value.mid != id) throw AppException.Conflict($"Barcode {b} is already assigned to {owner.Value.name}. Each barcode can belong to one medicine only.");
            }
            var now = Clock.NowIso();
            long mid;
            string before = "";
            var p = new
            {
                name = m.Name.Trim(), generic = m.Generic.Trim(), brand = m.Brand.Trim(), mfr = m.Manufacturer.Trim(), cat = m.Category.Trim(), form = m.DosageForm.Trim(),
                strength = m.Strength.Trim(), unit = m.BaseUnit.Trim(), min = m.MinStockQty, tax = Money.PercentToBp(m.TaxRatePercent), rx = m.RxRequired ? 1 : 0,
                sched = m.Schedule ?? "", urdu = m.UrduName.Trim(), rack = m.Rack.Trim(), cost = Money.ToE4(m.DefaultPurchaseCost), active = m.IsActive ? 1 : 0, now,
            };
            if (id == null)
            {
                mid = c.ExecuteScalar<long>(@"INSERT INTO medicines(code, name, generic, brand, manufacturer, category, dosage_form, strength, base_unit, min_stock_qty, tax_bp, rx_required,
                    schedule, urdu_name, rack, default_purchase_e4, is_active, created_at, updated_at)
                    VALUES('TMP-'||hex(randomblob(6)), @name,@generic,@brand,@mfr,@cat,@form,@strength,@unit,@min,@tax,@rx,@sched,@urdu,@rack,@cost,@active,@now,@now) RETURNING id", p, tx);
                c.Execute("UPDATE medicines SET code=@code WHERE id=@mid", new { code = $"M-{mid:00000}", mid }, tx);
            }
            else
            {
                mid = id.Value;
                before = Json.Serialize(ToDto(c, tx, mid));
                c.Execute(@"UPDATE medicines SET name=@name, generic=@generic, brand=@brand, manufacturer=@mfr, category=@cat, dosage_form=@form, strength=@strength, base_unit=@unit,
                    min_stock_qty=@min, tax_bp=@tax, rx_required=@rx, schedule=@sched, urdu_name=@urdu, rack=@rack, default_purchase_e4=@cost, is_active=@active, updated_at=@now WHERE id=@mid",
                    new { p.name, p.generic, p.brand, p.mfr, p.cat, p.form, p.strength, p.unit, p.min, p.tax, p.rx, p.sched, p.urdu, p.rack, p.cost, p.active, p.now, mid }, tx);
            }

            var existing = Packs(c, tx, mid);
            var keep = new HashSet<long>();
            long defaultId = 0, purchaseId = 0;
            foreach (var pk in packs)
            {
                var row = existing.FirstOrDefault(e => (pk.Id > 0 && e.Id == pk.Id) || e.Name.Equals(pk.Name.Trim(), StringComparison.OrdinalIgnoreCase));
                long packId;
                var price = Money.ToPaisa(pk.SalePrice);
                if (row == null)
                {
                    packId = c.ExecuteScalar<long>("INSERT INTO medicine_packs(medicine_id, name, base_qty, is_sellable, sale_paisa) VALUES(@mid,@n,@q,@s,@p) RETURNING id",
                        new { mid, n = pk.Name.Trim(), q = pk.BaseQty, s = pk.IsSellable ? 1 : 0, p = price }, tx);
                }
                else
                {
                    packId = row.Id;
                    if (row.BaseQty != pk.BaseQty && c.ExecuteScalar<long>("SELECT COUNT(*) FROM sales_items WHERE medicine_id=@mid", new { mid }, tx) + c.ExecuteScalar<long>("SELECT COUNT(*) FROM purchase_items WHERE pack_id=@packId", new { packId }, tx) > 0)
                        throw AppException.Validation($"The size of pack {row.Name} cannot change after it was used. Add a new pack level instead.");
                    if (row.SalePaisa != price)
                    {
                        c.Execute("INSERT INTO price_history(medicine_id, pack_name, old_paisa, new_paisa, changed_by, changed_at, reason) VALUES(@mid,@n,@o,@p,@u,@t,@r)",
                            new { mid, n = row.Name, o = row.SalePaisa, p = price, u = ctx.UserId, t = now, r = m.PriceChangeReason ?? "" }, tx);
                        Audit.Write(c, tx, ctx, "PRICE_CHANGE", "medicines", $"{m.Name} / {row.Name}", Money.Format(Money.FromPaisa(row.SalePaisa)), Money.Format(pk.SalePrice), m.PriceChangeReason ?? "");
                    }
                    c.Execute("UPDATE medicine_packs SET name=@n, base_qty=@q, is_sellable=@s, sale_paisa=@p, is_active=1 WHERE id=@packId",
                        new { n = pk.Name.Trim(), q = pk.BaseQty, s = pk.IsSellable ? 1 : 0, p = price, packId }, tx);
                }
                keep.Add(packId);
                if (pk == defaultSale) defaultId = packId;
                if (pk == purchase) purchaseId = packId;
            }
            foreach (var e in existing.Where(e => !keep.Contains(e.Id))) c.Execute("UPDATE medicine_packs SET is_active=0, is_sellable=0 WHERE id=@id", new { id = e.Id }, tx);
            c.Execute("UPDATE medicines SET default_sale_pack_id=@d, purchase_pack_id=@p WHERE id=@mid", new { d = defaultId, p = purchaseId, mid }, tx);

            c.Execute("DELETE FROM barcodes WHERE medicine_id=@mid", new { mid }, tx);
            foreach (var b in m.Barcodes.Where(b => b.Code.Trim().Length > 0).GroupBy(b => b.Code.Trim()).Select(g => g.First()))
            {
                long? packId = string.IsNullOrEmpty(b.PackName) ? defaultId : c.ExecuteScalar<long?>("SELECT id FROM medicine_packs WHERE medicine_id=@mid AND name=@n", new { mid, n = b.PackName }, tx) ?? defaultId;
                c.Execute("INSERT INTO barcodes(code, medicine_id, pack_id) VALUES(@code,@mid,@packId)", new { code = b.Code.Trim(), mid, packId }, tx);
            }
            UpdateFts(c, tx, mid);
            c.Execute("INSERT INTO medicine_costs(medicine_id, avg_cost_e4, qty_on_hand, updated_at) VALUES(@mid, @cost, 0, @now) ON CONFLICT(medicine_id) DO NOTHING", new { mid, p.cost, now }, tx);
            Audit.Write(c, tx, ctx, id == null ? "MEDICINE_CREATED" : "MEDICINE_CHANGED", "medicines", m.Name, before, Json.Serialize(ToDto(c, tx, mid)));
            return mid;
        });
        return new OkResult { Id = newId, Message = "Medicine saved." };
    }

    internal static void UpdateFts(SqliteConnection c, SqliteTransaction tx, long id)
    {
        c.Execute("DELETE FROM medicine_fts WHERE rowid=@id", new { id }, tx);
        c.Execute("INSERT INTO medicine_fts(rowid, name, generic, brand, manufacturer) SELECT id, name, generic, brand, manufacturer FROM medicines WHERE id=@id", new { id }, tx);
    }
}

public sealed class ImportService(Db db)
{
    private sealed class Job
    {
        public long Id { get; set; }
        public string FileName { get; set; } = "";
        public string Status { get; set; } = "";
        public string ColumnsJson { get; set; } = "[]";
        public string MappingJson { get; set; } = "{}";
        public string DataJson { get; set; } = "[]";
        public string IssuesJson { get; set; } = "[]";
        public long Created { get; set; }
        public long Skipped { get; set; }
    }

    public ImportPreview Upload(Ctx ctx, string fileName, byte[] content)
    {
        var rows = Parse(fileName, content);
        if (rows.Count == 0) throw AppException.Validation("The file has no data rows. Put column names in row 1 and data from row 2.");
        if (rows.Count > 50000) throw AppException.Validation("The file has more than 50,000 rows. Split it into smaller files.");
        var columns = rows[0].Keys.ToList();
        if (columns.All(string.IsNullOrWhiteSpace)) throw AppException.Validation("The first row has no column names.");
        var mapping = Suggest(columns);
        var id = db.Write((c, tx) => c.ExecuteScalar<long>(@"INSERT INTO import_jobs(file_name, status, rows_read, columns_json, mapping_json, data_json, created_by, created_at)
            VALUES(@f,'UPLOADED',@n,@cols,@map,@data,@u,@t) RETURNING id",
            new { f = fileName, n = rows.Count, cols = Json.Serialize(columns), map = Json.Serialize(mapping), data = Json.Serialize(rows), u = ctx.UserId, t = Clock.NowIso() }, tx));
        return Map(id, mapping);
    }

    private static List<Dictionary<string, string>> Parse(string fileName, byte[] content)
    {
        var ext = Path.GetExtension(fileName).ToLowerInvariant();
        if (ext == ".xlsx")
        {
            using var ms = new MemoryStream(content);
            var list = new List<Dictionary<string, string>>();
            foreach (IDictionary<string, object?> r in MiniExcel.Query(ms, useHeaderRow: true, excelType: ExcelType.XLSX))
            {
                var d = r.ToDictionary(kv => kv.Key.Trim(), kv => Convert.ToString(kv.Value, CultureInfo.InvariantCulture)?.Trim() ?? "");
                if (d.Values.All(string.IsNullOrWhiteSpace)) continue;
                list.Add(d);
            }
            return list;
        }
        if (ext == ".csv" || ext == ".txt") return ParseCsv(Encoding.UTF8.GetString(content).TrimStart('﻿'));
        throw AppException.Validation("Use an Excel (.xlsx) or CSV file. Older .xls files must be saved as .xlsx first.");
    }

    internal static List<Dictionary<string, string>> ParseCsv(string text)
    {
        var records = new List<List<string>>();
        var field = new StringBuilder();
        var record = new List<string>();
        bool inQuotes = false;
        for (int i = 0; i < text.Length; i++)
        {
            var ch = text[i];
            if (inQuotes)
            {
                if (ch == '"' && i + 1 < text.Length && text[i + 1] == '"') { field.Append('"'); i++; }
                else if (ch == '"') inQuotes = false;
                else field.Append(ch);
            }
            else if (ch == '"') inQuotes = true;
            else if (ch == ',') { record.Add(field.ToString()); field.Clear(); }
            else if (ch == '\n' || ch == '\r')
            {
                if (ch == '\r' && i + 1 < text.Length && text[i + 1] == '\n') i++;
                record.Add(field.ToString()); field.Clear();
                records.Add(record); record = new List<string>();
            }
            else field.Append(ch);
        }
        if (field.Length > 0 || record.Count > 0) { record.Add(field.ToString()); records.Add(record); }
        records = records.Where(r => r.Any(f => f.Trim().Length > 0)).ToList();
        if (records.Count == 0) return new();
        var header = records[0].Select((h, i) => h.Trim().Length == 0 ? $"Column {i + 1}" : h.Trim()).ToList();
        return records.Skip(1).Select(r => header.Select((h, i) => (h, v: i < r.Count ? r[i].Trim() : "")).GroupBy(x => x.h).ToDictionary(g => g.Key, g => g.First().v)).ToList();
    }

    private static Dictionary<string, string> Suggest(List<string> columns)
    {
        var map = new Dictionary<string, string>();
        string? Find(params string[] keys) => columns.FirstOrDefault(c => !map.ContainsValue(c) && keys.Any(k => c.ToLowerInvariant().Replace("_", " ").Contains(k)));
        void Set(string field, params string[] keys) { var c = Find(keys); if (c != null) map[field] = c; }
        Set(ImportFields.Barcode, "barcode", "ean", "gtin", "upc");
        Set(ImportFields.Generic, "generic", "salt", "formula", "molecule");
        Set(ImportFields.Name, "medicine", "item name", "product", "name", "item", "description");
        Set(ImportFields.Brand, "brand");
        Set(ImportFields.Manufacturer, "manufacturer", "company", "mfr", "mfg by", "maker");
        Set(ImportFields.Category, "category", "group", "class");
        Set(ImportFields.DosageForm, "dosage", "form", "type");
        Set(ImportFields.Strength, "strength", "potency", "dose");
        Set(ImportFields.SubPackSize, "strip size", "sub pack size", "sub-pack size");
        Set(ImportFields.SubPackName, "sub pack", "sub-pack", "strip name");
        Set(ImportFields.PackSize, "pack size", "pack qty", "packing", "units per pack", "pack");
        Set(ImportFields.PackName, "pack name", "pack unit", "purchase unit");
        Set(ImportFields.BaseUnit, "base unit", "unit");
        Set(ImportFields.PurchasePrice, "purchase", "tp", "trade price", "cost");
        Set(ImportFields.SalePrice, "mrp", "retail", "sale price", "selling", "price");
        Set(ImportFields.MinStock, "min", "reorder");
        Set(ImportFields.TaxRate, "tax", "gst", "sales tax");
        Set(ImportFields.RxRequired, "rx", "prescription");
        Set(ImportFields.Rack, "rack", "shelf", "location", "bin");
        return map;
    }

    public ImportPreview Preview(long id)
    {
        var job = Load(id);
        return Build(job, Json.Deserialize<List<ImportIssue>>(job.IssuesJson)!);
    }

    private Job Load(long id) => db.Read(c => c.QuerySingleOrDefault<Job>("SELECT * FROM import_jobs WHERE id=@id", new { id })) ?? throw AppException.NotFound("Import");

    private static ImportPreview Build(Job job, List<ImportIssue> issues)
    {
        var rows = Json.Deserialize<List<Dictionary<string, string>>>(job.DataJson)!;
        var errorRows = issues.Where(i => i.Severity == "ERROR").Select(i => i.Row).Distinct().Count();
        return new ImportPreview
        {
            JobId = job.Id, FileName = job.FileName, RowsRead = rows.Count, Columns = Json.Deserialize<List<string>>(job.ColumnsJson)!,
            Mapping = Json.Deserialize<Dictionary<string, string>>(job.MappingJson)!, Errors = errorRows,
            Warnings = issues.Where(i => i.Severity == "WARNING").Select(i => i.Row).Distinct().Count(), Ready = rows.Count - errorRows,
            Issues = issues.Take(2000).ToList(), Status = job.Status,
        };
    }

    private sealed class Candidate
    {
        public int Row;
        public string Name = "", Barcode = "", Generic = "", Brand = "", Manufacturer = "", Category = "", DosageForm = "", Strength = "", BaseUnit = "", PackName = "", SubPackName = "", Rack = "";
        public int PackSize, SubPackSize, MinStock;
        public decimal Purchase, Sale, Tax;
        public bool Rx;
    }

    private (List<Candidate> valid, List<ImportIssue> issues) Validate(SqliteConnection c, List<Dictionary<string, string>> rows, Dictionary<string, string> map)
    {
        string V(Dictionary<string, string> r, string field) => map.TryGetValue(field, out var col) && col != ImportFields.Ignore && r.TryGetValue(col, out var v) ? (v ?? "").Trim() : "";
        var issues = new List<ImportIssue>();
        var list = new List<Candidate>();
        if (!map.ContainsKey(ImportFields.Name)) { issues.Add(new ImportIssue { Row = 1, Problem = "Map a column to Medicine name.", Severity = "ERROR" }); return (list, issues); }
        var dbBarcodes = c.Query<string>("SELECT code FROM barcodes").ToHashSet();
        var dbKeys = c.Query<string>("SELECT lower(name||'|'||strength||'|'||manufacturer) FROM medicines").ToHashSet();
        var fileBarcodes = new Dictionary<string, int>();
        var fileKeys = new HashSet<string>();
        for (int i = 0; i < rows.Count; i++)
        {
            var r = rows[i];
            var rowNo = i + 2;
            var cand = new Candidate { Row = rowNo, Name = V(r, ImportFields.Name), Barcode = V(r, ImportFields.Barcode), Generic = V(r, ImportFields.Generic), Brand = V(r, ImportFields.Brand),
                Manufacturer = V(r, ImportFields.Manufacturer), Category = V(r, ImportFields.Category), DosageForm = V(r, ImportFields.DosageForm), Strength = V(r, ImportFields.Strength),
                BaseUnit = V(r, ImportFields.BaseUnit), PackName = V(r, ImportFields.PackName), SubPackName = V(r, ImportFields.SubPackName), Rack = V(r, ImportFields.Rack) };
            var errors = new List<string>();
            void Err(string p) { errors.Add(p); issues.Add(new ImportIssue { Row = rowNo, Name = cand.Name, Problem = p, Severity = "ERROR" }); }
            void Warn(string p) => issues.Add(new ImportIssue { Row = rowNo, Name = cand.Name, Problem = p, Severity = "WARNING" });
            if (cand.Name.Length == 0) Err("Medicine name is missing");
            var packText = V(r, ImportFields.PackSize);
            if (packText.Length == 0) cand.PackSize = 1;
            else if (!int.TryParse(packText, NumberStyles.Integer, CultureInfo.InvariantCulture, out cand.PackSize) || cand.PackSize < 1) Err($"Pack size '{packText}' is not a whole number of base units");
            var subText = V(r, ImportFields.SubPackSize);
            if (subText.Length > 0)
            {
                if (!int.TryParse(subText, out cand.SubPackSize) || cand.SubPackSize < 1) Err($"Sub-pack size '{subText}' is not a whole number");
                else if (cand.PackSize > 1 && (cand.SubPackSize >= cand.PackSize || cand.PackSize % cand.SubPackSize != 0)) Err($"Sub-pack size {cand.SubPackSize} must divide the pack size {cand.PackSize}");
            }
            var sale = V(r, ImportFields.SalePrice);
            if (sale.Length > 0 && (!Money.TryParse(sale, out cand.Sale) || cand.Sale < 0)) Err($"Sale price '{sale}' is not a number");
            else if (cand.Sale == 0) Warn("Sale price is 0.00");
            var pur = V(r, ImportFields.PurchasePrice);
            if (pur.Length > 0 && (!Money.TryParse(pur, out cand.Purchase) || cand.Purchase < 0)) Err($"Purchase price '{pur}' is not a number");
            var tax = V(r, ImportFields.TaxRate);
            if (tax.Length > 0 && (!Money.TryParse(tax.TrimEnd('%'), out cand.Tax) || cand.Tax < 0 || cand.Tax > 100)) Err($"Tax rate '{tax}' must be 0 to 100");
            var min = V(r, ImportFields.MinStock);
            if (min.Length > 0 && (!int.TryParse(min, out cand.MinStock) || cand.MinStock < 0)) Warn($"Minimum stock '{min}' ignored");
            var rx = V(r, ImportFields.RxRequired).ToLowerInvariant();
            cand.Rx = rx is "yes" or "y" or "1" or "true";
            if (cand.Barcode.Length > 0)
            {
                if (!cand.Barcode.All(char.IsLetterOrDigit) || cand.Barcode.Length > 40) Err($"Barcode '{cand.Barcode}' has invalid characters");
                else if (dbBarcodes.Contains(cand.Barcode)) Err($"Barcode {cand.Barcode} already belongs to a medicine in the system");
                else if (fileBarcodes.TryGetValue(cand.Barcode, out var other)) Err($"Barcode {cand.Barcode} also on row {other}");
                else fileBarcodes[cand.Barcode] = rowNo;
                if (cand.Barcode.All(char.IsDigit) && cand.Barcode.Length is 8 or 12 or 13 or 14 && !Gtin.IsValid(cand.Barcode)) Warn($"Barcode {cand.Barcode} has a wrong check digit");
            }
            var key = $"{cand.Name}|{cand.Strength}|{cand.Manufacturer}".ToLowerInvariant();
            if (dbKeys.Contains(key)) Warn("A medicine with the same name, strength and manufacturer already exists");
            else if (!fileKeys.Add(key) && cand.Name.Length > 0) Warn("Looks like a duplicate of another row (same name, strength and manufacturer)");
            if (errors.Count == 0) list.Add(cand);
        }
        return (list, issues);
    }

    public ImportPreview Map(long id, Dictionary<string, string> mapping)
    {
        var job = Load(id);
        if (job.Status is "COMMITTED" or "UNDONE") throw AppException.Conflict("This import is already finished.");
        var rows = Json.Deserialize<List<Dictionary<string, string>>>(job.DataJson)!;
        mapping = mapping.Where(kv => kv.Value != ImportFields.Ignore && !string.IsNullOrEmpty(kv.Value)).ToDictionary(kv => kv.Key, kv => kv.Value);
        var (_, issues) = db.Read(c => Validate(c, rows, mapping));
        db.Write((c, tx) => c.Execute("UPDATE import_jobs SET mapping_json=@m, issues_json=@i, status='VALIDATED' WHERE id=@id", new { m = Json.Serialize(mapping), i = Json.Serialize(issues), id }, tx));
        job.MappingJson = Json.Serialize(mapping);
        job.Status = "VALIDATED";
        return Build(job, issues);
    }

    public ImportPreview Commit(Ctx ctx, long id, bool dryRun)
    {
        var job = Load(id);
        if (job.Status is "COMMITTED" or "UNDONE") throw AppException.Conflict("This import is already finished.");
        var rows = Json.Deserialize<List<Dictionary<string, string>>>(job.DataJson)!;
        var map = Json.Deserialize<Dictionary<string, string>>(job.MappingJson)!;
        var (valid, issues) = db.Read(c => Validate(c, rows, map));
        if (dryRun)
        {
            var p = Build(job, issues);
            p.Status = $"DRY RUN: {valid.Count} medicines would be created, {rows.Count - valid.Count} rows skipped. Nothing was saved.";
            return p;
        }
        db.Write((c, tx) =>
        {
            var now = Clock.NowIso();
            foreach (var r in valid)
            {
                var baseUnit = r.BaseUnit.Length > 0 ? r.BaseUnit : r.DosageForm.Equals("Capsule", StringComparison.OrdinalIgnoreCase) ? "Capsule"
                    : r.DosageForm.Length > 0 && !r.DosageForm.Equals("Tablet", StringComparison.OrdinalIgnoreCase) && r.PackSize == 1 ? "Bottle" : "Tablet";
                var mid = c.ExecuteScalar<long>(@"INSERT INTO medicines(code, name, generic, brand, manufacturer, category, dosage_form, strength, base_unit, min_stock_qty, tax_bp, rx_required, rack,
                    default_purchase_e4, import_job_id, created_at, updated_at)
                    VALUES('TMP-'||hex(randomblob(6)),@n,@g,@b,@mf,@cat,@f,@s,@u,@min,@tax,@rx,@rack,@cost,@job,@now,@now) RETURNING id",
                    new { n = r.Name, g = r.Generic, b = r.Brand, mf = r.Manufacturer, cat = r.Category, f = r.DosageForm, s = r.Strength, u = baseUnit, min = r.MinStock,
                        tax = Money.PercentToBp(r.Tax), rx = r.Rx ? 1 : 0, rack = r.Rack, cost = Money.ToE4(r.PackSize > 0 ? r.Purchase / r.PackSize : 0), job = id, now }, tx);
                c.Execute("UPDATE medicines SET code=@code WHERE id=@mid", new { code = $"M-{mid:00000}", mid }, tx);
                long defaultPack, purchasePack;
                if (r.PackSize == 1)
                {
                    defaultPack = purchasePack = c.ExecuteScalar<long>("INSERT INTO medicine_packs(medicine_id, name, base_qty, is_sellable, sale_paisa) VALUES(@mid,@n,1,1,@p) RETURNING id",
                        new { mid, n = r.PackName.Length > 0 ? r.PackName : baseUnit, p = Money.ToPaisa(r.Sale) }, tx);
                }
                else
                {
                    var unitPrice = Money.Round2(r.Sale / r.PackSize);
                    var basePack = c.ExecuteScalar<long>("INSERT INTO medicine_packs(medicine_id, name, base_qty, is_sellable, sale_paisa) VALUES(@mid,@n,1,1,@p) RETURNING id",
                        new { mid, n = baseUnit, p = Money.ToPaisa(unitPrice) }, tx);
                    purchasePack = c.ExecuteScalar<long>("INSERT INTO medicine_packs(medicine_id, name, base_qty, is_sellable, sale_paisa) VALUES(@mid,@n,@q,1,@p) RETURNING id",
                        new { mid, n = r.PackName.Length > 0 && !r.PackName.Equals(baseUnit, StringComparison.OrdinalIgnoreCase) ? r.PackName : "Pack", q = r.PackSize, p = Money.ToPaisa(r.Sale) }, tx);
                    defaultPack = purchasePack;
                    if (r.SubPackSize > 1)
                        defaultPack = c.ExecuteScalar<long>("INSERT INTO medicine_packs(medicine_id, name, base_qty, is_sellable, sale_paisa) VALUES(@mid,@n,@q,1,@p) RETURNING id",
                            new { mid, n = r.SubPackName.Length > 0 ? r.SubPackName : "Strip", q = r.SubPackSize, p = Money.ToPaisa(r.Sale * r.SubPackSize / r.PackSize) }, tx);
                    _ = basePack;
                }
                c.Execute("UPDATE medicines SET default_sale_pack_id=@d, purchase_pack_id=@p WHERE id=@mid", new { d = defaultPack, p = purchasePack, mid }, tx);
                if (r.Barcode.Length > 0) c.Execute("INSERT INTO barcodes(code, medicine_id, pack_id) VALUES(@b,@mid,@p)", new { b = r.Barcode, mid, p = defaultPack }, tx);
                c.Execute("INSERT INTO medicine_costs(medicine_id, avg_cost_e4, qty_on_hand, updated_at) VALUES(@mid,@cost,0,@now)", new { mid, cost = Money.ToE4(r.PackSize > 0 ? r.Purchase / r.PackSize : 0), now }, tx);
                CatalogueService.UpdateFts(c, tx, mid);
            }
            foreach (var cat in valid.Select(v => v.Category).Where(x => x.Length > 0).Distinct(StringComparer.OrdinalIgnoreCase))
                c.Execute("INSERT OR IGNORE INTO lists(kind, name) VALUES('category', @cat)", new { cat }, tx);
            foreach (var mf in valid.Select(v => v.Manufacturer).Where(x => x.Length > 0).Distinct(StringComparer.OrdinalIgnoreCase))
                c.Execute("INSERT OR IGNORE INTO lists(kind, name) VALUES('manufacturer', @mf)", new { mf }, tx);
            c.Execute("UPDATE import_jobs SET status='COMMITTED', created=@n, skipped=@s, issues_json=@i, committed_at=@t WHERE id=@id",
                new { n = valid.Count, s = rows.Count - valid.Count, i = Json.Serialize(issues), t = now, id }, tx);
            Audit.Write(c, tx, ctx, "MEDICINES_IMPORTED", "import", $"IMP-{id:0000} {job.FileName}", after: $"{valid.Count} created, {rows.Count - valid.Count} skipped");
        });
        var done = Load(id);
        var preview = Build(done, issues);
        preview.Status = $"Imported {valid.Count} medicines in one transaction (IMP-{id:0000}). {rows.Count - valid.Count} rows skipped.";
        return preview;
    }

    public OkResult Undo(Ctx ctx, long id)
    {
        db.Write((c, tx) =>
        {
            var status = c.ExecuteScalar<string?>("SELECT status FROM import_jobs WHERE id=@id", new { id }, tx) ?? throw AppException.NotFound("Import");
            if (status != "COMMITTED") throw AppException.Conflict("Only a completed import can be undone.");
            var used = c.ExecuteScalar<long>(@"SELECT (SELECT COUNT(*) FROM batches b JOIN medicines m ON m.id=b.medicine_id WHERE m.import_job_id=@id)
                + (SELECT COUNT(*) FROM purchase_items p JOIN medicines m ON m.id=p.medicine_id WHERE m.import_job_id=@id)", new { id }, tx);
            if (used > 0) throw AppException.Conflict("This import cannot be undone because stock was already received for some of its medicines.");
            var ids = c.Query<long>("SELECT id FROM medicines WHERE import_job_id=@id", new { id }, tx).ToList();
            foreach (var mid in ids)
            {
                c.Execute("DELETE FROM barcodes WHERE medicine_id=@mid", new { mid }, tx);
                c.Execute("DELETE FROM medicine_costs WHERE medicine_id=@mid", new { mid }, tx);
                c.Execute("DELETE FROM price_history WHERE medicine_id=@mid", new { mid }, tx);
                c.Execute("UPDATE medicines SET default_sale_pack_id=NULL, purchase_pack_id=NULL WHERE id=@mid", new { mid }, tx);
                c.Execute("DELETE FROM medicine_packs WHERE medicine_id=@mid", new { mid }, tx);
                c.Execute("DELETE FROM medicine_fts WHERE rowid=@mid", new { mid }, tx);
                c.Execute("DELETE FROM medicines WHERE id=@mid", new { mid }, tx);
            }
            c.Execute("UPDATE import_jobs SET status='UNDONE', undone_at=@t WHERE id=@id", new { t = Clock.NowIso(), id }, tx);
            Audit.Write(c, tx, ctx, "IMPORT_UNDONE", "import", $"IMP-{id:0000}", after: $"{ids.Count} medicines removed");
        });
        return new OkResult { Message = "Import undone." };
    }

    public string ErrorsCsv(long id)
    {
        var job = Load(id);
        var issues = Json.Deserialize<List<ImportIssue>>(job.IssuesJson)!;
        var sb = new StringBuilder("﻿Row,Medicine,Severity,Problem\r\n");
        foreach (var i in issues) sb.Append(i.Row).Append(',').Append(Csv(i.Name)).Append(',').Append(i.Severity).Append(',').Append(Csv(i.Problem)).Append("\r\n");
        return sb.ToString();
    }

    internal static string Csv(string s) => s.IndexOfAny(new[] { ',', '"', '\n', '\r' }) >= 0 ? "\"" + s.Replace("\"", "\"\"") + "\"" : s;
}
