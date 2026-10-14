using Dapper;
using MediCare.Contracts;
using MediCare.Core;
using MediCare.Host.Domain;
using MediCare.Host.Infrastructure;
using MediCare.Host.Security;
using Microsoft.Data.Sqlite;

namespace MediCare.Host.Modules;

public static class StockModule
{
    public static void Map(WebApplication app)
    {
        var g = app.MapGroup("/api/v1");
        g.MapGet("/batches", (string? view, string? q, int? window, StockService s) => s.Batches(view ?? "ALL", q, window)).Perm(Perm.StockView);
        g.MapGet("/batches/{id:long}/movements", (long id, StockService s) => s.Movements(id)).Perm(Perm.StockView);
        g.MapPost("/stock/adjustments/preview", (AdjustmentRequest r, StockService s) => s.Preview(r)).Perm(Perm.StockAdjust);
        g.MapPost("/stock/adjustments", (HttpContext http, AdjustmentRequest r, StockService s) => s.Adjust(http.Ctx(), r, http.Tokens(r.ApprovalToken), "ADJUST", null)).Perm(Perm.StockAdjust);
        g.MapPost("/stock/quarantine", (HttpContext http, QuarantineRequest r, StockService s) => s.Quarantine(http.Ctx(), r, http.Tokens(r.ApprovalToken))).Perm(Perm.StockAdjust);
        g.MapGet("/stock/counts", (StockService s) => s.Counts()).Perm(Perm.StockAdjust);
        g.MapPost("/stock/counts", (HttpContext http, StockCountCreate r, StockService s) => s.CreateCount(http.Ctx(), r)).Perm(Perm.StockAdjust);
        g.MapGet("/stock/counts/{id:long}", (long id, StockService s) => s.GetCount(id)).Perm(Perm.StockAdjust);
        g.MapPut("/stock/counts/{id:long}", (long id, StockCountSave r, StockService s) => s.SaveCount(id, r)).Perm(Perm.StockAdjust);
        g.MapPost("/stock/counts/{id:long}/post", (long id, HttpContext http, PostRequest r, StockService s) => s.PostCount(http.Ctx(), id, r, http.Tokens(r.ApprovalTokens.ToArray()))).Perm(Perm.StockAdjust);
        g.MapPost("/stock/counts/{id:long}/cancel", (long id, HttpContext http, StockService s) => s.CancelCount(http.Ctx(), id)).Perm(Perm.StockAdjust);
    }
}

public sealed class StockService(Db db, SettingsService settings)
{
    public List<BatchRow> Batches(string view, string? q, int? window)
    {
        var days = window ?? settings.GetInt("stock.nearExpiryDays", 90);
        return db.Read(c =>
        {
            var today = Clock.Today;
            var todayText = Clock.DateText(today);
            var horizon = Clock.DateText(today.AddDays(days));
            var rows = c.Query(@"SELECT b.id, b.medicine_id, m.name, m.base_unit, m.min_stock_qty, b.batch_no, b.expiry_date, b.rack, sb.state, sb.qty, IFNULL(s.name,'') AS supplier, IFNULL(mc.avg_cost_e4,0) AS avg
                FROM stock_balances sb JOIN batches b ON b.id=sb.batch_id JOIN medicines m ON m.id=b.medicine_id
                LEFT JOIN suppliers s ON s.id=b.supplier_id LEFT JOIN medicine_costs mc ON mc.medicine_id=b.medicine_id
                WHERE sb.qty > 0 AND (@q IS NULL OR m.name LIKE '%'||@q||'%' OR b.batch_no LIKE @q||'%' OR m.generic LIKE '%'||@q||'%')
                  AND (@view = 'ALL'
                    OR (@view = 'SALEABLE' AND sb.state='SALEABLE' AND b.expiry_date > @today)
                    OR (@view = 'NEAR' AND sb.state='SALEABLE' AND b.expiry_date > @today AND b.expiry_date <= @horizon)
                    OR (@view = 'EXPIRED' AND sb.state='SALEABLE' AND b.expiry_date <= @today)
                    OR (@view = 'QUARANTINE' AND sb.state='QUARANTINE'))
                ORDER BY b.expiry_date, m.name LIMIT 5000", new { q = string.IsNullOrWhiteSpace(q) ? null : q.Trim(), view, today = todayText, horizon }).ToList();
            var packCache = new Dictionary<long, List<CatalogueService.PackRow>>();
            List<CatalogueService.PackRow> Packs(long mid) => packCache.TryGetValue(mid, out var p) ? p : packCache[mid] = CatalogueService.Packs(c, null, mid);
            var result = rows.Select(r =>
            {
                var exp = Clock.ParseDate((string)r.expiry_date);
                var expired = !Fefo.IsSaleable(exp, today);
                var qty = (int)(long)r.qty;
                return new BatchRow
                {
                    BatchId = r.id, MedicineId = r.medicine_id, Medicine = r.name, BatchNo = r.batch_no, Expiry = exp, DaysLeft = Fefo.DaysLeft(exp, today),
                    State = r.state == "QUARANTINE" ? "QUARANTINE" : expired ? "EXPIRED" : Fefo.DaysLeft(exp, today) <= days ? "NEAR EXPIRY" : "SALEABLE",
                    Qty = qty, QtyText = CatalogueService.QtyText(qty, Packs((long)r.medicine_id), (string)r.base_unit), Rack = r.rack,
                    CostValue = Money.Round2(qty * Money.FromE4((long)r.avg)), Supplier = r.supplier, MinStockQty = (int)(long)r.min_stock_qty,
                };
            }).ToList();
            if (view == "LOW")
            {
                result = c.Query(@"SELECT m.id, m.name, m.base_unit, m.min_stock_qty, m.rack, IFNULL(mc.avg_cost_e4,0) AS avg,
                        IFNULL((SELECT SUM(sb.qty) FROM stock_balances sb JOIN batches b ON b.id=sb.batch_id WHERE b.medicine_id=m.id AND sb.state='SALEABLE' AND b.expiry_date > @today),0) AS qty
                    FROM medicines m LEFT JOIN medicine_costs mc ON mc.medicine_id=m.id WHERE m.is_active=1 AND m.min_stock_qty > 0
                      AND (@q IS NULL OR m.name LIKE '%'||@q||'%')", new { today = todayText, q = string.IsNullOrWhiteSpace(q) ? null : q.Trim() })
                    .Where(r => (long)r.qty < (long)r.min_stock_qty)
                    .Select(r => new BatchRow
                    {
                        MedicineId = r.id, Medicine = r.name, State = "LOW STOCK", Qty = (int)(long)r.qty, Rack = r.rack, MinStockQty = (int)(long)r.min_stock_qty,
                        QtyText = CatalogueService.QtyText((int)(long)r.qty, Packs((long)r.id), (string)r.base_unit) + $" (min {CatalogueService.QtyText((int)(long)r.min_stock_qty, Packs((long)r.id), (string)r.base_unit)})",
                        CostValue = Money.Round2((long)r.qty * Money.FromE4((long)r.avg)),
                    }).OrderBy(r => r.Medicine).ToList();
            }
            return result;
        });
    }

    public List<MovementRow> Movements(long batchId) => db.Read(c => c.Query<(string at, string source_type, string doc_no, string state, long qty_delta, string? user)>(
            @"SELECT sm.at, sm.source_type, sm.doc_no, sm.state, sm.qty_delta, u.full_name FROM stock_movements sm LEFT JOIN users u ON u.id=sm.user_id WHERE sm.batch_id=@batchId ORDER BY sm.id", new { batchId })
        .Select(r => new MovementRow { At = Clock.ToPkt(r.at), Type = TypeName(r.source_type), DocNo = r.doc_no, State = r.state, QtyDelta = (int)r.qty_delta, User = r.user ?? "system" }).ToList());

    public static string TypeName(string t) => t switch
    {
        "PI" => "Purchase", "SI" => "Sale", "SR" => "Sales return", "PR" => "Purchase return", "SA" => "Adjustment", "SC" => "Stock count",
        "QM" => "Moved to quarantine", "WO" => "Write-off", "REV" => "Reversal", _ => t,
    };

    private sealed record Line(long BatchId, long MedicineId, string Medicine, string BatchNo, string State, int System, int Physical, long AvgE4);

    private List<Line> Resolve(SqliteConnection c, SqliteTransaction? tx, AdjustmentRequest r)
    {
        var list = new List<Line>();
        foreach (var l in r.Lines)
        {
            if (l.PhysicalQty < 0) throw AppException.Validation("Physical quantity cannot be negative.");
            if (l.State != StockStates.Saleable && l.State != StockStates.Quarantine) throw AppException.Validation("State must be SALEABLE or QUARANTINE.");
            var b = c.QueryOne<(long medicine_id, string name, string batch_no)>("SELECT b.medicine_id, m.name, b.batch_no FROM batches b JOIN medicines m ON m.id=b.medicine_id WHERE b.id=@id", new { id = l.BatchId }, tx)
                    ?? throw AppException.NotFound("Batch");
            var system = (int)StockLedger.Balance(c, tx, l.BatchId, l.State);
            var (avg, _) = StockLedger.Cost(c, tx, b.medicine_id);
            list.Add(new Line(l.BatchId, b.medicine_id, b.name, b.batch_no, l.State, system, l.PhysicalQty, avg));
        }
        return list;
    }

    private (decimal value, decimal maxPercent, bool needs) Assess(List<Line> lines)
    {
        var value = lines.Sum(l => Math.Abs(l.Physical - l.System) * Money.FromE4(l.AvgE4));
        var maxPct = lines.Select(l => l.System == 0 ? (l.Physical == 0 ? 0m : 100m) : Math.Abs(l.Physical - l.System) * 100m / l.System).DefaultIfEmpty(0).Max();
        var needs = value > settings.GetDecimal("approval.adjustAbove", 1000) || maxPct > settings.GetDecimal("approval.adjustPercent", 5);
        return (Money.Round2(value), Money.Round2(maxPct), needs);
    }

    public AdjustmentPreview Preview(AdjustmentRequest r) => db.Read(c =>
    {
        var (value, pct, needs) = Assess(Resolve(c, null, r));
        return new AdjustmentPreview { Value = value, MaxPercent = pct, NeedsApproval = needs };
    });

    public OkResult Adjust(Ctx ctx, AdjustmentRequest r, List<string?> tokens, string kind, long? countId)
    {
        if (string.IsNullOrWhiteSpace(r.Reason)) throw AppException.Validation("Choose a reason for the adjustment.");
        if (r.Lines.Count == 0) throw AppException.Validation("Add at least one batch.");
        var hash = Idempotency.HashOf(r);
        return db.Write((c, tx) =>
        {
            var stored = Idempotency.Find<OkResult>(c, tx, r.IdempotencyKey, "adjustment", hash);
            if (stored != null) return stored;
            Guards.DayOpen(c, tx, ctx, tokens, r.IdempotencyKey);
            var lines = Resolve(c, tx, r).Where(l => l.Physical != l.System).ToList();
            if (lines.Count == 0) throw AppException.Validation("Physical and system quantities are the same. Nothing to adjust.");
            var (value, _, needs) = Assess(lines);
            long? approval = needs ? ApprovalService.Require(c, tx, ctx, tokens, ApprovalActions.Adjustment, r.IdempotencyKey, value) : null;
            var no = DocNumbers.Next(c, tx, settings, "adjustment");
            var adjId = c.ExecuteScalar<long>(@"INSERT INTO stock_adjustments(no, idempotency_key, kind, at, business_date, reason, notes, user_id, approval_id, count_id)
                VALUES(@no,@key,@kind,@t,@bd,@reason,@notes,@u,@a,@count) RETURNING id",
                new { no, key = r.IdempotencyKey, kind, t = Clock.NowIso(), bd = Clock.BusinessDate(), reason = r.Reason, notes = r.Notes, u = ctx.UserId, a = approval, count = countId }, tx);
            long shrinkSaleable = 0, shrinkQuarantine = 0;
            foreach (var l in lines)
            {
                var diff = l.Physical - l.System;
                long valuePaisa;
                if (diff < 0)
                {
                    var avg = StockLedger.CostOut(c, tx, l.MedicineId, -diff);
                    valuePaisa = -Money.ToPaisa(Costing.Cogs(-diff, Money.FromE4(avg)));
                }
                else
                {
                    valuePaisa = Money.ToPaisa(Costing.Cogs(diff, Money.FromE4(l.AvgE4)));
                    StockLedger.CostIn(c, tx, l.MedicineId, diff, Money.FromPaisa(valuePaisa));
                }
                var itemId = c.ExecuteScalar<long>(@"INSERT INTO stock_adjustment_items(adjustment_id, batch_id, state, system_qty, physical_qty, diff_qty, cost_e4, value_paisa)
                    VALUES(@adjId,@b,@s,@sys,@phys,@diff,@cost,@val) RETURNING id", new { adjId, b = l.BatchId, s = l.State, sys = l.System, phys = l.Physical, diff, cost = l.AvgE4, val = valuePaisa }, tx);
                StockLedger.Move(c, tx, ctx, l.BatchId, l.State, diff, kind == "COUNT" ? "SC" : "SA", adjId, itemId, no, l.AvgE4);
                if (l.State == StockStates.Saleable) shrinkSaleable += valuePaisa; else shrinkQuarantine += valuePaisa;
            }
            var j = new Journal();
            j.Dr(Acct.Shrinkage, -(shrinkSaleable + shrinkQuarantine));
            j.Dr(Acct.Inventory, shrinkSaleable).Dr(Acct.InventoryQuarantine, shrinkQuarantine);
            long? je = j.TotalDebit == 0 ? null : j.Post(c, tx, settings, ctx, kind == "COUNT" ? "SC" : "SA", adjId, no, $"{r.Reason}: {r.Notes}".Trim(' ', ':'));
            c.Execute("UPDATE stock_adjustments SET value_paisa=@v, journal_entry_id=@je WHERE id=@adjId", new { v = shrinkSaleable + shrinkQuarantine, je, adjId }, tx);
            Audit.Write(c, tx, ctx, "STOCK_ADJUSTMENT", "stock", no, after: string.Join("; ", lines.Select(l => $"{l.Medicine} {l.BatchNo} {l.System}->{l.Physical}")), reason: r.Reason, approver: ApprovalService.ApproverName(c, tx, approval));
            var result = new OkResult { Id = adjId, No = no, Message = $"Adjustment {no} posted. Value {Money.FormatRs(Money.FromPaisa(shrinkSaleable + shrinkQuarantine))}." };
            Idempotency.Save(c, tx, r.IdempotencyKey, "adjustment", hash, result);
            return result;
        });
    }

    public OkResult Quarantine(Ctx ctx, QuarantineRequest r, List<string?> tokens)
    {
        if (r.Qty <= 0) throw AppException.Validation("Quantity must be more than zero.");
        if (string.IsNullOrWhiteSpace(r.Reason)) throw AppException.Validation("Enter a reason.");
        var hash = Idempotency.HashOf(r);
        return db.Write((c, tx) =>
        {
            var stored = Idempotency.Find<OkResult>(c, tx, r.IdempotencyKey, "quarantine", hash);
            if (stored != null) return stored;
            Guards.DayOpen(c, tx, ctx, tokens, r.IdempotencyKey);
            var b = c.QueryOne<(long medicine_id, string name, string batch_no)>("SELECT b.medicine_id, m.name, b.batch_no FROM batches b JOIN medicines m ON m.id=b.medicine_id WHERE b.id=@id", new { id = r.BatchId }, tx)
                    ?? throw AppException.NotFound("Batch");
            var (avg, _) = StockLedger.Cost(c, tx, b.medicine_id);
            var value = Money.ToPaisa(Costing.Cogs(r.Qty, Money.FromE4(avg)));
            var writeOff = r.Action == "WRITE_OFF";
            long? approval = writeOff ? ApprovalService.Require(c, tx, ctx, tokens, ApprovalActions.WriteOff, r.IdempotencyKey, Money.FromPaisa(value)) : null;
            var no = DocNumbers.Next(c, tx, settings, "adjustment");
            var adjId = c.ExecuteScalar<long>(@"INSERT INTO stock_adjustments(no, idempotency_key, kind, at, business_date, reason, notes, value_paisa, user_id, approval_id)
                VALUES(@no,@key,@kind,@t,@bd,@reason,'',@v,@u,@a) RETURNING id",
                new { no, key = r.IdempotencyKey, kind = writeOff ? "WRITE_OFF" : "QUARANTINE", t = Clock.NowIso(), bd = Clock.BusinessDate(), reason = r.Reason, v = writeOff ? -value : 0, u = ctx.UserId, a = approval }, tx);
            Journal j;
            if (writeOff)
            {
                var itemId = c.ExecuteScalar<long>(@"INSERT INTO stock_adjustment_items(adjustment_id, batch_id, state, system_qty, physical_qty, diff_qty, cost_e4, value_paisa)
                    VALUES(@adjId,@b,'QUARANTINE',@sys,@phys,@diff,@cost,@val) RETURNING id",
                    new { adjId, b = r.BatchId, sys = StockLedger.Balance(c, tx, r.BatchId, StockStates.Quarantine), phys = StockLedger.Balance(c, tx, r.BatchId, StockStates.Quarantine) - r.Qty, diff = -r.Qty, cost = avg, val = -value }, tx);
                StockLedger.Move(c, tx, ctx, r.BatchId, StockStates.Quarantine, -r.Qty, "WO", adjId, itemId, no, avg);
                StockLedger.CostOut(c, tx, b.medicine_id, r.Qty);
                j = new Journal().Dr(Acct.Shrinkage, value).Cr(Acct.InventoryQuarantine, value);
            }
            else
            {
                var itemId = c.ExecuteScalar<long>(@"INSERT INTO stock_adjustment_items(adjustment_id, batch_id, state, system_qty, physical_qty, diff_qty, cost_e4, value_paisa)
                    VALUES(@adjId,@b,'SALEABLE',@sys,@phys,@diff,@cost,@val) RETURNING id",
                    new { adjId, b = r.BatchId, sys = StockLedger.Balance(c, tx, r.BatchId, StockStates.Saleable), phys = StockLedger.Balance(c, tx, r.BatchId, StockStates.Saleable) - r.Qty, diff = -r.Qty, cost = avg, val = value }, tx);
                StockLedger.Move(c, tx, ctx, r.BatchId, StockStates.Saleable, -r.Qty, "QM", adjId, itemId, no, avg);
                StockLedger.Move(c, tx, ctx, r.BatchId, StockStates.Quarantine, r.Qty, "QM", adjId, itemId, no, avg);
                j = new Journal().Dr(Acct.InventoryQuarantine, value).Cr(Acct.Inventory, value);
            }
            long? je = value == 0 ? null : j.Post(c, tx, settings, ctx, writeOff ? "WO" : "QM", adjId, no, $"{(writeOff ? "Write-off" : "Quarantine")} {b.name} {b.batch_no}: {r.Reason}");
            c.Execute("UPDATE stock_adjustments SET journal_entry_id=@je WHERE id=@adjId", new { je, adjId }, tx);
            Audit.Write(c, tx, ctx, writeOff ? "WRITE_OFF" : "QUARANTINE_MOVE", "stock", no, after: $"{b.name} {b.batch_no} x {r.Qty}", reason: r.Reason, approver: ApprovalService.ApproverName(c, tx, approval));
            var result = new OkResult { Id = adjId, No = no, Message = writeOff ? $"Written off {r.Qty} units ({Money.FormatRs(Money.FromPaisa(value))})." : $"Moved {r.Qty} units to quarantine." };
            Idempotency.Save(c, tx, r.IdempotencyKey, "quarantine", hash, result);
            return result;
        });
    }

    // ---------- stock counts (FR-081) ----------
    public List<StockCountDto> Counts() => db.Read(c => c.Query<(long id, string no, string scope, string status, string frozen_at)>("SELECT id, no, scope, status, frozen_at FROM stock_counts ORDER BY id DESC LIMIT 100")
        .Select(r => new StockCountDto { Id = r.id, No = r.no, Scope = r.scope, Status = r.status, FrozenAt = Clock.ToPkt(r.frozen_at) }).ToList());

    public StockCountDto CreateCount(Ctx ctx, StockCountCreate r)
    {
        var id = db.Write((c, tx) =>
        {
            var scope = (r.RackPrefix.Trim().Length > 0 ? $"Rack {r.RackPrefix.Trim()}*" : "All racks") + (r.Category.Trim().Length > 0 ? $", {r.Category.Trim()}" : "");
            var no = DocNumbers.Next(c, tx, settings, "count");
            var frozenMovement = c.ExecuteScalar<long>("SELECT IFNULL(MAX(id),0) FROM stock_movements", transaction: tx);
            var countId = c.ExecuteScalar<long>("INSERT INTO stock_counts(no, scope, frozen_at, frozen_movement_id, created_by) VALUES(@no,@scope,@t,@fm,@u) RETURNING id",
                new { no, scope, t = Clock.NowIso(), fm = frozenMovement, u = ctx.UserId }, tx);
            var n = c.Execute(@"INSERT INTO stock_count_items(count_id, batch_id, frozen_qty)
                SELECT @countId, b.id, IFNULL(sb.qty,0) FROM batches b JOIN medicines m ON m.id=b.medicine_id
                LEFT JOIN stock_balances sb ON sb.batch_id=b.id AND sb.state='SALEABLE'
                WHERE IFNULL(sb.qty,0) > 0 AND (@rack = '' OR b.rack LIKE @rack||'%' OR (b.rack = '' AND m.rack LIKE @rack||'%')) AND (@cat = '' OR m.category = @cat)",
                new { countId, rack = r.RackPrefix.Trim(), cat = r.Category.Trim() }, tx);
            if (n == 0) throw AppException.Validation("No stock matches this rack or category.");
            Audit.Write(c, tx, ctx, "STOCK_COUNT_STARTED", "stock", no, after: $"{scope}, {n} batches");
            return countId;
        });
        return GetCount(id);
    }

    public StockCountDto GetCount(long id) => db.Read(c =>
    {
        var h = c.QueryOne<(long id, string no, string scope, string status, string frozen_at, long frozen_movement_id)>("SELECT id, no, scope, status, frozen_at, frozen_movement_id FROM stock_counts WHERE id=@id", new { id })
                ?? throw AppException.NotFound("Stock count");
        return new StockCountDto
        {
            Id = h.id, No = h.no, Scope = h.scope, Status = h.status, FrozenAt = Clock.ToPkt(h.frozen_at),
            Items = c.Query<(long id, long batch_id, string med, string batch_no, string rack, long frozen_qty, long? counted_qty, long since)>(
                @"SELECT i.id, i.batch_id, m.name, b.batch_no, CASE WHEN b.rack<>'' THEN b.rack ELSE m.rack END, i.frozen_qty, i.counted_qty,
                    IFNULL((SELECT SUM(qty_delta) FROM stock_movements sm WHERE sm.batch_id=i.batch_id AND sm.state='SALEABLE' AND sm.id > @fm),0)
                  FROM stock_count_items i JOIN batches b ON b.id=i.batch_id JOIN medicines m ON m.id=b.medicine_id WHERE i.count_id=@id ORDER BY 5, m.name", new { id, fm = h.frozen_movement_id })
                .Select(r => new StockCountItem { Id = r.id, BatchId = r.batch_id, Medicine = r.med, BatchNo = r.batch_no, Rack = r.rack, FrozenQty = (int)r.frozen_qty, CountedQty = (int?)r.counted_qty, MovementsSince = (int)r.since }).ToList(),
        };
    });

    public StockCountDto SaveCount(long id, StockCountSave r)
    {
        db.Write((c, tx) =>
        {
            var status = c.ExecuteScalar<string?>("SELECT status FROM stock_counts WHERE id=@id", new { id }, tx) ?? throw AppException.NotFound("Stock count");
            if (status != "OPEN") throw AppException.Conflict("This count is already closed.");
            foreach (var i in r.Items)
            {
                if (i.CountedQty < 0) throw AppException.Validation("Counted quantity cannot be negative.");
                c.Execute("UPDATE stock_count_items SET counted_qty=@q WHERE id=@iid AND count_id=@id", new { q = i.CountedQty, iid = i.Id, id }, tx);
            }
        });
        return GetCount(id);
    }

    public OkResult PostCount(Ctx ctx, long id, PostRequest r, List<string?> tokens)
    {
        var count = GetCount(id);
        if (count.Status != "OPEN") throw AppException.Conflict("This count is already closed.");
        var lines = count.Items.Where(i => i.CountedQty.HasValue)
            .Select(i => new AdjustmentLineInput { BatchId = i.BatchId, State = StockStates.Saleable, PhysicalQty = i.CountedQty!.Value + i.MovementsSince }).ToList();
        if (lines.Count == 0) throw AppException.Validation("Enter at least one counted quantity.");
        var req = new AdjustmentRequest { IdempotencyKey = r.IdempotencyKey, Reason = "Stock count", Notes = $"{count.No} ({count.Scope})", Lines = lines };
        var current = db.Read(c => Resolve(c, null, req));
        OkResult result;
        if (current.All(l => l.Physical == l.System)) result = new OkResult { Message = "Count matches the system. No adjustment needed." };
        else result = Adjust(ctx, req, tokens, "COUNT", id);
        db.Write((c, tx) =>
        {
            c.Execute("UPDATE stock_counts SET status='POSTED', adjustment_id=@a WHERE id=@id", new { a = result.Id, id }, tx);
            Audit.Write(c, tx, ctx, "STOCK_COUNT_POSTED", "stock", count.No, after: result.Message ?? "");
        });
        return result;
    }

    public OkResult CancelCount(Ctx ctx, long id)
    {
        db.Write((c, tx) =>
        {
            var no = c.ExecuteScalar<string?>("SELECT no FROM stock_counts WHERE id=@id AND status='OPEN'", new { id }, tx) ?? throw AppException.Conflict("Only an open count can be cancelled.");
            c.Execute("UPDATE stock_counts SET status='CANCELLED' WHERE id=@id", new { id }, tx);
            Audit.Write(c, tx, ctx, "STOCK_COUNT_CANCELLED", "stock", no);
        });
        return new OkResult();
    }
}
