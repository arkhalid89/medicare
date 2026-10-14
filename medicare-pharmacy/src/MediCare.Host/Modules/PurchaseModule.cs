using System.Globalization;
using Dapper;
using MediCare.Contracts;
using MediCare.Core;
using MediCare.Host.Domain;
using MediCare.Host.Infrastructure;
using MediCare.Host.Security;
using Microsoft.Data.Sqlite;

namespace MediCare.Host.Modules;

public static class PurchaseModule
{
    public static void Map(WebApplication app)
    {
        var g = app.MapGroup("/api/v1");
        g.MapGet("/purchases", (string? status, long? supplierId, DateTime? from, DateTime? to, string? q, PurchaseService s) => s.List(status, supplierId, from, to, q)).Perm(Perm.PurchasesView);
        g.MapGet("/purchases/{id:long}", (long id, PurchaseService s) => s.Get(id)).Perm(Perm.PurchasesView);
        g.MapPost("/purchases", (HttpContext http, PurchaseDto d, PurchaseService s) => s.SaveDraft(http.Ctx(), null, d)).Perm(Perm.PurchasesPost);
        g.MapPut("/purchases/{id:long}", (long id, HttpContext http, PurchaseDto d, PurchaseService s) => s.SaveDraft(http.Ctx(), id, d)).Perm(Perm.PurchasesPost);
        g.MapDelete("/purchases/{id:long}", (long id, HttpContext http, PurchaseService s) => s.DeleteDraft(http.Ctx(), id)).Perm(Perm.PurchasesPost);
        g.MapPost("/purchases/{id:long}/post", (long id, HttpContext http, PostRequest r, PurchaseService s) => s.Post(http.Ctx(), id, r, http.Tokens(r.ApprovalTokens.ToArray()))).Perm(Perm.PurchasesPost);
        g.MapPost("/purchases/{id:long}/reverse", (long id, HttpContext http, ReverseRequest r, PurchaseService s) => s.Reverse(http.Ctx(), id, r)).Perm(Perm.PurchasesPost);
        g.MapPost("/purchase-returns", (HttpContext http, PurchaseReturnRequest r, PurchaseService s) => s.Return(http.Ctx(), r, http.Tokens())).Perm(Perm.PurchasesReturn);
    }
}

public sealed class PurchaseService(Db db, SettingsService settings)
{
    private sealed class Computed
    {
        public long Gross, Disc, Tax, Charge, Net, UnitCostE4;
        public int BaseUnits;
    }

    private static List<Computed> Compute(IList<PurchaseLineDto> lines, decimal charges)
    {
        var list = new List<Computed>();
        foreach (var l in lines)
        {
            var gross = Money.Round2(l.Qty * l.Rate);
            var disc = Money.Round2(gross * l.DiscountPercent / 100m);
            var tax = Money.Round2((gross - disc) * l.TaxPercent / 100m);
            list.Add(new Computed { Gross = Money.ToPaisa(gross), Disc = Money.ToPaisa(disc), Tax = Money.ToPaisa(tax), BaseUnits = (l.Qty + l.FreeQty) * Math.Max(1, l.PackBaseQty) });
        }
        var shares = Costing.AllocatePro(Money.Round2(charges), list.Select(x => Money.FromPaisa(x.Gross - x.Disc + x.Tax)).ToList());
        for (int i = 0; i < list.Count; i++)
        {
            var x = list[i];
            x.Charge = Money.ToPaisa(shares[i]);
            x.Net = x.Gross - x.Disc + x.Tax + x.Charge;
            x.UnitCostE4 = x.BaseUnits > 0 ? Money.ToE4(Costing.UnitCost(Money.FromPaisa(x.Net), x.BaseUnits)) : 0;
        }
        return list;
    }

    public List<PurchaseSummary> List(string? status, long? supplierId, DateTime? from, DateTime? to, string? q) => db.Read(c =>
    {
        var f = Clock.DateText(from ?? Clock.Today.AddMonths(-3));
        var t = Clock.DateText(to ?? Clock.Today);
        return c.Query(@"SELECT p.*, s.name AS supplier, (SELECT COUNT(*) FROM purchase_items WHERE purchase_id=p.id) AS lines FROM purchases p JOIN suppliers s ON s.id=p.supplier_id
                         WHERE (@status IS NULL OR p.status=@status) AND (@sid IS NULL OR p.supplier_id=@sid) AND (p.status='DRAFT' OR p.inv_date BETWEEN @f AND @t)
                           AND (@q IS NULL OR p.no LIKE '%'||@q||'%' OR p.supplier_inv_no LIKE '%'||@q||'%' OR s.name LIKE '%'||@q||'%')
                         ORDER BY CASE p.status WHEN 'DRAFT' THEN 0 ELSE 1 END, p.inv_date DESC, p.id DESC LIMIT 500",
                new { status = string.IsNullOrEmpty(status) ? null : status, sid = supplierId, f, t, q = string.IsNullOrWhiteSpace(q) ? null : q.Trim() })
            .Select(r => new PurchaseSummary
            {
                Id = r.id, No = r.no, Status = r.status, Supplier = r.supplier, SupplierInvNo = r.supplier_inv_no, InvDate = Clock.ParseDate((string)r.inv_date),
                DueDate = Clock.ParseDateOrNull((string?)r.due_date), Net = Money.FromPaisa((long)r.net_paisa), Lines = (int)(long)r.lines,
                Outstanding = r.status == "POSTED" && r.is_opening == 0 ? Money.FromPaisa((long)r.net_paisa - (long)r.paid_paisa - (long)r.returned_paisa) : 0,
            }).ToList();
    });

    public PurchaseDto Get(long id) => db.Read(c => Load(c, null, id));

    private static PurchaseDto Load(SqliteConnection c, SqliteTransaction? tx, long id)
    {
        var r = c.QuerySingleOrDefault(@"SELECT p.*, s.name AS supplier, u.full_name AS posted_name FROM purchases p JOIN suppliers s ON s.id=p.supplier_id
                                         LEFT JOIN users u ON u.id=p.posted_by WHERE p.id=@id", new { id }, tx) ?? throw AppException.NotFound("Purchase");
        var dto = new PurchaseDto
        {
            Id = r.id, No = r.no, Status = r.status, SupplierId = r.supplier_id, SupplierName = r.supplier, SupplierInvNo = r.supplier_inv_no,
            InvDate = Clock.ParseDate((string)r.inv_date), DueDate = Clock.ParseDateOrNull((string?)r.due_date), IsOpeningStock = r.is_opening == 1, Notes = r.notes,
            Charges = Money.FromPaisa((long)r.charges_paisa), PaidNow = Money.FromPaisa((long)r.paid_now_paisa), PaidFromAccountId = r.paid_account_id, PaidReference = r.paid_reference,
            Gross = Money.FromPaisa((long)r.gross_paisa), Discount = Money.FromPaisa((long)r.discount_paisa), Tax = Money.FromPaisa((long)r.tax_paisa), Net = Money.FromPaisa((long)r.net_paisa),
            Paid = Money.FromPaisa((long)r.paid_paisa), Returned = Money.FromPaisa((long)r.returned_paisa),
            PostedAt = Clock.ToPktOrNull((string?)r.posted_at), PostedBy = r.posted_name,
        };
        dto.Outstanding = dto.Status == "POSTED" && !dto.IsOpeningStock ? dto.Net - dto.Paid - dto.Returned : 0;
        dto.Lines = c.Query(@"SELECT pi.*, m.name AS med FROM purchase_items pi JOIN medicines m ON m.id=pi.medicine_id WHERE pi.purchase_id=@id ORDER BY pi.line_no", new { id }, tx)
            .Select(l => new PurchaseLineDto
            {
                Id = l.id, MedicineId = l.medicine_id, MedicineName = l.med, PackId = l.pack_id, PackName = l.pack_name, PackBaseQty = (int)(long)l.pack_base_qty, BatchNo = l.batch_no,
                Expiry = Clock.ParseDateOrNull((string?)l.expiry_date), Mfg = Clock.ParseDateOrNull((string?)l.mfg_date), Qty = (int)(long)l.qty, FreeQty = (int)(long)l.free_qty,
                Rate = Money.FromPaisa((long)l.rate_paisa), DiscountPercent = Money.BpToPercent((long)l.disc_bp), TaxPercent = Money.BpToPercent((long)l.tax_bp),
                Mrp = Money.FromPaisa((long)l.mrp_paisa), SalePrice = Money.FromPaisa((long)l.sale_paisa), Rack = l.rack, CaptureMethod = l.capture_method,
                Net = Money.FromPaisa((long)l.net_paisa), UnitCost = Money.FromE4((long)l.unit_cost_e4), BatchId = l.batch_id, ReturnedBaseQty = (int)(long)l.returned_base_qty,
            }).ToList();
        return dto;
    }

    public OkResult SaveDraft(Ctx ctx, long? id, PurchaseDto d)
    {
        if (d.SupplierId <= 0) throw AppException.Validation("Choose the supplier.");
        if (d.Charges < 0 || d.PaidNow < 0) throw AppException.Validation("Charges and payment cannot be negative.");
        foreach (var l in d.Lines)
        {
            if (l.Qty <= 0) throw AppException.Validation($"{l.MedicineName}: quantity must be more than zero.");
            if (l.FreeQty < 0 || l.Rate < 0 || l.DiscountPercent < 0 || l.DiscountPercent > 100 || l.TaxPercent < 0 || l.TaxPercent > 100)
                throw AppException.Validation($"{l.MedicineName}: check free quantity, rate, discount and tax.");
        }
        var newId = db.Write((c, tx) =>
        {
            if (c.ExecuteScalar<long>("SELECT COUNT(*) FROM suppliers WHERE id=@id", new { id = d.SupplierId }, tx) == 0) throw AppException.NotFound("Supplier");
            foreach (var l in d.Lines)
            {
                var pack = c.QueryOne<(long medicine_id, long base_qty, string name)>("SELECT medicine_id, base_qty, name FROM medicine_packs WHERE id=@id", new { id = l.PackId }, tx)
                           ?? throw AppException.Validation($"{l.MedicineName}: choose the pack.");
                if (pack.medicine_id != l.MedicineId) throw AppException.Validation($"{l.MedicineName}: pack does not belong to the medicine.");
                l.PackBaseQty = (int)pack.base_qty;
                l.PackName = pack.name;
            }
            var comp = Compute(d.Lines, d.Charges);
            var now = Clock.NowIso();
            var p = new
            {
                sid = d.SupplierId, inv = d.SupplierInvNo.Trim(), date = Clock.DateText(d.InvDate == default ? Clock.Today : d.InvDate), due = d.DueDate == null ? null : Clock.DateText(d.DueDate.Value),
                open = d.IsOpeningStock ? 1 : 0, notes = d.Notes.Trim(), ch = Money.ToPaisa(d.Charges), paid = d.IsOpeningStock ? 0 : Money.ToPaisa(d.PaidNow), pacct = d.PaidFromAccountId,
                pref = d.PaidReference.Trim(), gross = comp.Sum(x => x.Gross), disc = comp.Sum(x => x.Disc), tax = comp.Sum(x => x.Tax), net = comp.Sum(x => x.Net), now, u = ctx.UserId,
            };
            long pid;
            if (id == null)
                pid = c.ExecuteScalar<long>(@"INSERT INTO purchases(supplier_id, supplier_inv_no, inv_date, due_date, is_opening, notes, charges_paisa, paid_now_paisa, paid_account_id, paid_reference,
                    gross_paisa, discount_paisa, tax_paisa, net_paisa, created_by, created_at, updated_at)
                    VALUES(@sid,@inv,@date,@due,@open,@notes,@ch,@paid,@pacct,@pref,@gross,@disc,@tax,@net,@u,@now,@now) RETURNING id", p, tx);
            else
            {
                pid = id.Value;
                var status = c.ExecuteScalar<string?>("SELECT status FROM purchases WHERE id=@pid", new { pid }, tx) ?? throw AppException.NotFound("Purchase");
                if (status != "DRAFT") throw AppException.Conflict("A posted purchase cannot be edited. Use a purchase return or reversal.");
                c.Execute(@"UPDATE purchases SET supplier_id=@sid, supplier_inv_no=@inv, inv_date=@date, due_date=@due, is_opening=@open, notes=@notes, charges_paisa=@ch, paid_now_paisa=@paid,
                    paid_account_id=@pacct, paid_reference=@pref, gross_paisa=@gross, discount_paisa=@disc, tax_paisa=@tax, net_paisa=@net, updated_at=@now WHERE id=@pid",
                    new { p.sid, p.inv, p.date, p.due, p.open, p.notes, p.ch, p.paid, p.pacct, p.pref, p.gross, p.disc, p.tax, p.net, p.now, pid }, tx);
                c.Execute("DELETE FROM purchase_items WHERE purchase_id=@pid", new { pid }, tx);
            }
            for (int i = 0; i < d.Lines.Count; i++)
            {
                var l = d.Lines[i];
                var x = comp[i];
                c.Execute(@"INSERT INTO purchase_items(purchase_id, line_no, medicine_id, pack_id, pack_name, pack_base_qty, batch_no, expiry_date, mfg_date, qty, free_qty, rate_paisa, disc_bp, tax_bp,
                    gross_paisa, discount_paisa, tax_paisa, charge_paisa, net_paisa, unit_cost_e4, mrp_paisa, sale_paisa, rack, capture_method)
                    VALUES(@pid,@ln,@mid,@pk,@pn,@pb,@bn,@exp,@mfg,@q,@f,@r,@d,@t,@g,@di,@tx2,@ch,@n,@uc,@mrp,@sp,@rack,@cap)",
                    new
                    {
                        pid, ln = i + 1, mid = l.MedicineId, pk = l.PackId, pn = l.PackName, pb = l.PackBaseQty, bn = (l.BatchNo ?? "").Trim(), exp = l.Expiry == null ? null : Clock.DateText(l.Expiry.Value),
                        mfg = l.Mfg == null ? null : Clock.DateText(l.Mfg.Value), q = l.Qty, f = l.FreeQty, r = Money.ToPaisa(l.Rate), d = Money.PercentToBp(l.DiscountPercent), t = Money.PercentToBp(l.TaxPercent),
                        g = x.Gross, di = x.Disc, tx2 = x.Tax, ch = x.Charge, n = x.Net, uc = x.UnitCostE4, mrp = Money.ToPaisa(l.Mrp), sp = Money.ToPaisa(l.SalePrice), rack = (l.Rack ?? "").Trim(), cap = l.CaptureMethod,
                    }, tx);
            }
            return pid;
        });
        return new OkResult { Id = newId, Message = "Draft saved. Drafts do not change stock or balances." };
    }

    public OkResult DeleteDraft(Ctx ctx, long id)
    {
        db.Write((c, tx) =>
        {
            var status = c.ExecuteScalar<string?>("SELECT status FROM purchases WHERE id=@id", new { id }, tx) ?? throw AppException.NotFound("Purchase");
            if (status != "DRAFT") throw AppException.Conflict("Only drafts can be deleted.");
            c.Execute("DELETE FROM purchase_items WHERE purchase_id=@id", new { id }, tx);
            c.Execute("DELETE FROM purchases WHERE id=@id", new { id }, tx);
            Audit.Write(c, tx, ctx, "PURCHASE_DRAFT_DELETED", "purchases", $"draft {id}");
        });
        return new OkResult();
    }

    public PurchaseDto Post(Ctx ctx, long id, PostRequest r, List<string?> tokens)
    {
        var hash = Idempotency.HashOf(new { id, r.IdempotencyKey });
        return db.Write((c, tx) =>
        {
            var stored = Idempotency.Find<PurchaseDto>(c, tx, r.IdempotencyKey, "purchase-post", hash);
            if (stored != null) return stored;
            var d = Load(c, tx, id);
            if (d.Status != "DRAFT") throw AppException.Conflict($"Purchase {d.No} is already posted.");
            Guards.DayOpen(c, tx, ctx, tokens, r.IdempotencyKey);
            if (d.Lines.Count == 0) throw AppException.Validation("Add at least one medicine line.");
            var supplierActive = c.ExecuteScalar<long>("SELECT is_active FROM suppliers WHERE id=@id", new { id = d.SupplierId }, tx);
            if (supplierActive == 0) throw AppException.Validation($"Supplier {d.SupplierName} is inactive.");
            if (!d.IsOpeningStock && d.SupplierInvNo.Length > 0 &&
                c.ExecuteScalar<long>("SELECT COUNT(*) FROM purchases WHERE supplier_id=@s AND supplier_inv_no=@n AND status<>'DRAFT'", new { s = d.SupplierId, n = d.SupplierInvNo }, tx) > 0)
                throw AppException.Conflict($"Supplier invoice {d.SupplierInvNo} from {d.SupplierName} is already posted.");
            var batchRequired = settings.GetBool("stock.batchRequired");
            var today = Clock.Today;
            foreach (var l in d.Lines)
            {
                var active = c.ExecuteScalar<long>("SELECT is_active FROM medicines WHERE id=@id", new { id = l.MedicineId }, tx);
                if (active == 0) throw AppException.Validation($"Line {d.Lines.IndexOf(l) + 1}: {l.MedicineName} is inactive.");
                if (l.Expiry == null) throw AppException.Validation($"Line {d.Lines.IndexOf(l) + 1}: {l.MedicineName} needs an expiry date.");
                if (batchRequired && string.IsNullOrWhiteSpace(l.BatchNo)) throw AppException.Validation($"Line {d.Lines.IndexOf(l) + 1}: {l.MedicineName} needs a batch number.");
                if (l.Mfg != null && l.Expiry < l.Mfg) throw AppException.Validation($"Line {d.Lines.IndexOf(l) + 1}: expiry {Dates.Display(l.Expiry.Value)} is before the manufacturing date {Dates.Display(l.Mfg.Value)}. Check the date printed on the box.");
                if (!Fefo.IsSaleable(l.Expiry.Value, today)) throw AppException.Validation($"Line {d.Lines.IndexOf(l) + 1}: {l.MedicineName} batch {l.BatchNo} is already expired. Do not receive expired stock.");
            }
            var no = DocNumbers.Next(c, tx, settings, "purchase");
            var docNo = no;
            long inventory = 0;
            foreach (var l in d.Lines)
            {
                var batchNo = string.IsNullOrWhiteSpace(l.BatchNo) ? $"{no}-L{l.Id}" : l.BatchNo.Trim();
                var existing = c.QueryOne<(long id, string expiry_date)>("SELECT id, expiry_date FROM batches WHERE medicine_id=@m AND batch_no=@b", new { m = l.MedicineId, b = batchNo }, tx);
                long batchId;
                if (existing == null)
                {
                    batchId = c.ExecuteScalar<long>(@"INSERT INTO batches(medicine_id, batch_no, expiry_date, mfg_date, supplier_id, first_purchase_item_id, purchase_e4, sale_paisa, mrp_paisa, rack, created_at)
                        VALUES(@m,@b,@e,@mf,@s,@pi,@c,@sp,@mrp,@rack,@t) RETURNING id",
                        new { m = l.MedicineId, b = batchNo, e = Clock.DateText(l.Expiry!.Value), mf = l.Mfg == null ? null : Clock.DateText(l.Mfg.Value), s = d.SupplierId, pi = l.Id, c = Money.ToE4(l.UnitCost),
                            sp = l.SalePrice > 0 ? Money.ToPaisa(l.SalePrice) : (long?)null, mrp = l.Mrp > 0 ? Money.ToPaisa(l.Mrp) : (long?)null, rack = l.Rack ?? "", t = Clock.NowIso() }, tx);
                }
                else
                {
                    if (existing.Value.expiry_date != Clock.DateText(l.Expiry!.Value))
                        throw AppException.Validation($"{l.MedicineName} batch {batchNo} is already recorded with expiry {Dates.Display(Clock.ParseDate(existing.Value.expiry_date))}. Check the batch number and expiry.");
                    batchId = existing.Value.id;
                    c.Execute(@"UPDATE batches SET purchase_e4=@c, sale_paisa=COALESCE(@sp, sale_paisa), mrp_paisa=COALESCE(@mrp, mrp_paisa), rack=CASE WHEN @rack <> '' THEN @rack ELSE rack END WHERE id=@batchId",
                        new { c = Money.ToE4(l.UnitCost), sp = l.SalePrice > 0 ? Money.ToPaisa(l.SalePrice) : (long?)null, mrp = l.Mrp > 0 ? Money.ToPaisa(l.Mrp) : (long?)null, rack = l.Rack ?? "", batchId }, tx);
                }
                var baseUnits = (l.Qty + l.FreeQty) * l.PackBaseQty;
                c.Execute("UPDATE purchase_items SET batch_id=@batchId, batch_no=@batchNo WHERE id=@id", new { batchId, batchNo, id = l.Id }, tx);
                StockLedger.Move(c, tx, ctx, batchId, StockStates.Saleable, baseUnits, "PI", id, l.Id, docNo, Money.ToE4(l.UnitCost));
                StockLedger.CostIn(c, tx, l.MedicineId, baseUnits, l.Net);
                c.Execute("UPDATE medicines SET default_purchase_e4=@c, rack=CASE WHEN @rack <> '' THEN @rack ELSE rack END WHERE id=@m", new { c = Money.ToE4(l.UnitCost), rack = l.Rack ?? "", m = l.MedicineId }, tx);
                inventory += Money.ToPaisa(l.Net);
            }
            var j = new Journal().Dr(Acct.Inventory, inventory);
            if (d.IsOpeningStock) j.Cr(Acct.OpeningEquity, inventory);
            else j.Cr(Acct.Payables, inventory, "S", d.SupplierId);
            var je = j.Post(c, tx, settings, ctx, "PI", id, docNo, $"Purchase {d.SupplierInvNo} from {d.SupplierName}");
            c.Execute("UPDATE purchases SET status='POSTED', no=@no, idempotency_key=@key, business_date=@bd, posted_at=@t, posted_by=@u, journal_entry_id=@je WHERE id=@id",
                new { no, key = r.IdempotencyKey, bd = Clock.BusinessDate(), t = Clock.NowIso(), u = ctx.UserId, je, id }, tx);

            if (!d.IsOpeningStock && d.PaidNow > 0)
            {
                var paid = Money.ToPaisa(d.PaidNow);
                if (paid > inventory) throw AppException.Validation("Amount paid now is more than the invoice total.");
                var acct = d.PaidFromAccountId.HasValue ? CashAccounts.Get(c, tx, d.PaidFromAccountId.Value) : CashAccounts.ForMethod(c, tx, PaymentMethods.Cash, null);
                CashAccounts.EnsureFunds(c, tx, acct, paid);
                var shift = CashAccounts.ShiftFor(c, tx, ctx, acct);
                var payId = PartyService.InsertPayment(c, tx, ctx, settings, "PURCHASE", r.IdempotencyKey + ":pay", d.SupplierId, paid, acct, d.PaidReference, $"Paid with {no}", out var payNo);
                c.Execute("INSERT INTO payment_allocations(payment_id, purchase_id, amount_paisa) VALUES(@payId,@id,@paid)", new { payId, id, paid }, tx);
                c.Execute("UPDATE purchases SET paid_paisa = paid_paisa + @paid WHERE id=@id", new { paid, id }, tx);
                var pje = new Journal().Dr(Acct.Payables, paid, "S", d.SupplierId).CrAccount(acct.AccountId, paid, shift, "Paid on goods-in")
                    .Post(c, tx, settings, ctx, "SP", payId, payNo, $"Payment with {no}");
                c.Execute("UPDATE supplier_payments SET journal_entry_id=@pje WHERE id=@payId", new { pje, payId }, tx);
            }
            Audit.Write(c, tx, ctx, "PURCHASE_POSTED", "purchases", no, after: $"{d.SupplierName} inv {d.SupplierInvNo} net {Money.Format(Money.FromPaisa(inventory))}, {d.Lines.Count} lines");
            var result = Load(c, tx, id);
            Idempotency.Save(c, tx, r.IdempotencyKey, "purchase-post", hash, result);
            return result;
        });
    }

    public OkResult Reverse(Ctx ctx, long id, ReverseRequest r)
    {
        if (string.IsNullOrWhiteSpace(r.Reason)) throw AppException.Validation("Enter the reason for the reversal.");
        return db.Write((c, tx) =>
        {
            var d = Load(c, tx, id);
            if (d.Status != "POSTED") throw AppException.Conflict("Only a posted purchase can be reversed.");
            var approval = ApprovalService.Require(c, tx, ctx, new[] { r.ApprovalToken }, ApprovalActions.Reversal, $"REV:PI:{id}", 0);
            if (d.Paid > 0) throw AppException.Conflict("Payments are allocated to this purchase. Use a purchase return instead of a reversal.");
            if (d.Returned > 0) throw AppException.Conflict("Part of this purchase was already returned. Use a purchase return for the rest.");
            foreach (var l in d.Lines)
            {
                var units = (l.Qty + l.FreeQty) * l.PackBaseQty;
                if (StockLedger.Balance(c, tx, l.BatchId!.Value, StockStates.Saleable) < units)
                    throw AppException.Conflict($"{l.MedicineName} batch {l.BatchNo}: some received units were already sold or moved. Use a purchase return for the remaining stock.");
            }
            var no = DocNumbers.Next(c, tx, settings, "reversal");
            var revId = c.ExecuteScalar<long>("INSERT INTO reversals(no, idempotency_key, doc_type, doc_id, reason, approval_id, at, business_date, user_id) VALUES(@no,@key,'PI',@id,@reason,@a,@t,@bd,@u) RETURNING id",
                new { no, key = string.IsNullOrEmpty(r.IdempotencyKey) ? Guid.NewGuid().ToString() : r.IdempotencyKey, id, reason = r.Reason, a = approval, t = Clock.NowIso(), bd = Clock.BusinessDate(), u = ctx.UserId }, tx);
            foreach (var l in d.Lines)
            {
                var units = (l.Qty + l.FreeQty) * l.PackBaseQty;
                StockLedger.Move(c, tx, ctx, l.BatchId!.Value, StockStates.Saleable, -units, "REV", revId, l.Id, no, Money.ToE4(l.UnitCost));
                StockLedger.CostOutAtValue(c, tx, l.MedicineId, units, l.Net);
            }
            var origJe = c.ExecuteScalar<long>("SELECT journal_entry_id FROM purchases WHERE id=@id", new { id }, tx);
            var je = Journal.Reverse(c, tx, settings, ctx, origJe, "REV", revId, no, $"Reversal of {d.No}: {r.Reason}", null);
            c.Execute("UPDATE reversals SET journal_entry_id=@je WHERE id=@revId", new { je, revId }, tx);
            c.Execute("UPDATE purchases SET status='REVERSED', reversal_id=@revId WHERE id=@id", new { revId, id }, tx);
            Audit.Write(c, tx, ctx, "PURCHASE_REVERSED", "purchases", d.No ?? "", "POSTED", "REVERSED", r.Reason, ApprovalService.ApproverName(c, tx, approval));
            return new OkResult { Id = revId, No = no, Message = $"Purchase {d.No} reversed by {no}." };
        });
    }

    public OkResult Return(Ctx ctx, PurchaseReturnRequest r, List<string?> tokens)
    {
        var lines = r.Lines.Where(l => l.BaseQty > 0).ToList();
        if (lines.Count == 0) throw AppException.Validation("Enter a return quantity for at least one line.");
        var hash = Idempotency.HashOf(r);
        return db.Write((c, tx) =>
        {
            var stored = Idempotency.Find<OkResult>(c, tx, r.IdempotencyKey, "purchase-return", hash);
            if (stored != null) return stored;
            Guards.DayOpen(c, tx, ctx, tokens, r.IdempotencyKey);
            var d = Load(c, tx, r.PurchaseId);
            if (d.Status != "POSTED") throw AppException.Conflict("Returns need a posted purchase.");
            var no = DocNumbers.Next(c, tx, settings, "purchaseReturn");
            var retId = c.ExecuteScalar<long>(@"INSERT INTO purchase_returns(no, idempotency_key, purchase_id, supplier_id, credit_note_ref, at, business_date, amount_paisa, user_id)
                VALUES(@no,@key,@pid,@sid,@cn,@t,@bd,0,@u) RETURNING id",
                new { no, key = r.IdempotencyKey, pid = r.PurchaseId, sid = d.SupplierId, cn = r.CreditNoteRef.Trim(), t = Clock.NowIso(), bd = Clock.BusinessDate(), u = ctx.UserId }, tx);
            long total = 0, fromSaleable = 0, fromQuarantine = 0;
            foreach (var rl in lines)
            {
                var item = d.Lines.FirstOrDefault(l => l.Id == rl.PurchaseItemId) ?? throw AppException.Validation("A return line does not belong to this purchase.");
                if (rl.FromState != StockStates.Saleable && rl.FromState != StockStates.Quarantine) throw AppException.Validation("Return stock must come from saleable or quarantine.");
                var received = (item.Qty + item.FreeQty) * item.PackBaseQty;
                var remaining = received - item.ReturnedBaseQty;
                if (rl.BaseQty > remaining) throw AppException.Validation($"{item.MedicineName}: only {remaining} units of batch {item.BatchNo} can still be returned.");
                var amount = Money.ToPaisa(Money.FromE4(Money.ToE4(item.UnitCost)) * rl.BaseQty);
                c.Execute(@"INSERT INTO purchase_return_items(return_id, purchase_item_id, batch_id, from_state, base_qty, unit_cost_e4, amount_paisa, reason)
                    VALUES(@retId,@pi,@b,@s,@q,@uc,@amt,@reason)", new { retId, pi = item.Id, b = item.BatchId, s = rl.FromState, q = rl.BaseQty, uc = Money.ToE4(item.UnitCost), amt = amount, reason = rl.Reason }, tx);
                c.Execute("UPDATE purchase_items SET returned_base_qty = returned_base_qty + @q WHERE id=@id", new { q = rl.BaseQty, id = item.Id }, tx);
                StockLedger.Move(c, tx, ctx, item.BatchId!.Value, rl.FromState, -rl.BaseQty, "PR", retId, item.Id, no, Money.ToE4(item.UnitCost));
                StockLedger.CostOutAtValue(c, tx, item.MedicineId, rl.BaseQty, Money.FromPaisa(amount));
                total += amount;
                if (rl.FromState == StockStates.Saleable) fromSaleable += amount; else fromQuarantine += amount;
            }
            var j = new Journal();
            if (d.IsOpeningStock) j.Dr(Acct.OpeningEquity, total); else j.Dr(Acct.Payables, total, "S", d.SupplierId);
            j.Cr(Acct.Inventory, fromSaleable).Cr(Acct.InventoryQuarantine, fromQuarantine);
            var je = j.Post(c, tx, settings, ctx, "PR", retId, no, $"Return to {d.SupplierName} against {d.No} {r.CreditNoteRef}".Trim());
            c.Execute("UPDATE purchase_returns SET amount_paisa=@total, journal_entry_id=@je WHERE id=@retId", new { total, je, retId }, tx);
            c.Execute("UPDATE purchases SET returned_paisa = returned_paisa + @total WHERE id=@pid", new { total, pid = r.PurchaseId }, tx);
            Audit.Write(c, tx, ctx, "PURCHASE_RETURN", "purchases", no, after: $"{d.SupplierName} against {d.No}: {Money.Format(Money.FromPaisa(total))}");
            var result = new OkResult { Id = retId, No = no, Message = $"Purchase return {no} posted. Supplier credit Rs {Money.Format(Money.FromPaisa(total))}." };
            Idempotency.Save(c, tx, r.IdempotencyKey, "purchase-return", hash, result);
            return result;
        });
    }
}
