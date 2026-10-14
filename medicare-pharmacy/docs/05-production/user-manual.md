# MediCare Pharmacy: user manual

One page per role. Every screen shows its keys on the strip at the bottom; press **F1** for the list, **Ctrl+G** to jump to any screen, **Ctrl+L** to lock the PC.

## Everyone
- **Log in** with your own username and password. On first login choose your own password (10+ characters, letters and digits).
- **Lock** with Ctrl+L when you step away. The PC also locks itself after your idle time; unlocking keeps your work (cart, open screens).
- **Change password or PIN:** your name (top right) → Change password or PIN.
- The status bar is **green** while the PC is connected to the host. If it turns **red**, nothing can be saved; finish what you are typing and wait. Posted sales are always safe on the host.

## Cashier (New sale)
| Do this | Keys |
|---|---|
| Add a medicine | Scan the barcode, or type part of the name and press Enter (↑/↓ to pick, Enter to add) |
| Change quantity of the selected line | `+` / `−`, or type `*3` then Enter |
| Remove a line | Del |
| Edit a line (pack, quantity, line discount) | Enter on the line |
| Choose a customer (needed for credit) | F3 |
| Take payment | F4 → type the cash the customer gives → Enter. Split by typing card/wallet/bank/credit amounts; cash is the rest |
| Hold the sale / recall a held sale | F5 (with items = hold; empty sale = recall) |
| Discount | F6 (amount or %). Above your limit a manager approves with their PIN at payment |
| Choose a specific batch | F7 (expired and quarantined batches cannot be chosen) |
| Reprint the last receipt | F8 (marked DUPLICATE) |
| Return | F9 → scan/type the invoice no. → quantities, reason, restock or quarantine → refund method |
| Clear the sale | Ctrl+Del |

Rules the system enforces: expired stock cannot be sold; batch is picked by earliest expiry (FEFO) unless you scan a 2D code or choose one; Schedule B/D medicines ask for prescriber and patient; credit needs a named customer and stays within the credit limit unless approved.

If the host stops answering while you take payment, the sale is kept on this PC. Press F4 again when the status bar is green: MediCare offers to send **exactly the same sale**, which can never be saved twice.

**Shift:** Shift and day closing → Open my shift (count the float). At the end: Count cash and close my shift → enter notes and coins by denomination → if there is a difference, give the reason and ask the manager to approve.

## Manager / pharmacist-in-charge
- **Approvals:** at a counter, choose your name and type your PIN and a reason. Requests sent to your screen appear under **Approvals** (badge count) → F9 approve / F10 reject with your PIN.
- **Medicines:** Ctrl+N new, Enter edit. Packs and prices tab: pack names and how many base units each holds (Box 30, Strip 10, Tablet 1), sale price per pack, default counter pack. Barcodes tab: scan each pack's barcode or 2D code. A price change asks for a reason and is kept in price history.
- **Batches and stock:** views for near expiry, expired, quarantine and below minimum. F7 move to quarantine, F8 write off (from quarantine), F9 adjust quantity, F6 movements.
- **Stock counts:** New count for a rack prefix → type counted quantities (save any time with Ctrl+S) → Post differences.
- **Invoices:** search, reprint, return, and Ctrl+R reverse a wrong invoice (needs a reason and approval).
- **Day close:** when all shifts are closed, Shift and day closing → Close the day, then take the USB backup.

## Stock operator (Goods-in)
1. Goods-in → Ctrl+N. Choose the supplier, bill number and date.
2. Scan each box. A 2D code fills medicine, batch and expiry; scanning the same batch again adds one. For 1D barcodes or typed names, fill batch and expiry (MM/YY) in the grid.
3. Enter quantity, free quantity, rate, discount %, tax %, sale price and rack where needed. Freight goes in "Freight / other charges" and is spread over the lines' cost.
4. Ctrl+S saves a draft (changes nothing). **Post invoice** (Ctrl+Enter) adds the stock, updates average cost and the supplier balance. Print the goods-received note.
5. Returns to supplier: Goods-in list → choose the posted bill → F9.

## Accountant
- **Suppliers:** F10 pay (allocates to the oldest bills; anything left is an advance), F6 ledger with print.
- **Customers:** F10 receive payment, F6 ledger/statement.
- **Cash and bank:** balances and books by date; Cash in / out for paid-outs, owner capital or drawings; Transfer for bank deposits.
- **Expenses:** Ctrl+N voucher; above the threshold a manager approves.
- **Reports:** choose a report, set the filters, F5 run, Ctrl+P print, Ctrl+E Excel. Profit, ledgers, outstanding, cashbook, bankbook, expenses and stock valuation are in the Accounts and Stock groups.

## Administrator
- **Users and roles:** new users get a temporary password; give managers a PIN; F6 reset password, F7 unlock; F8 edit a role's permissions and discount limit.
- **Settings:** pharmacy details for the receipt, tax, thresholds, receipt footers (Urdu and English), payment methods, document numbering, backup time, shortcut keys, lists (manufacturers, categories, expense categories, adjustment reasons) and cash/bank accounts.
- **Backup and restore:** Ctrl+B back up now; Ctrl+U copy to USB (the copy is verified); Restore needs your password and the word RESTORE, and every PC must log in again afterwards.
- **Audit log:** filter by date, action or text; Enter shows before/after, reason and approver.
- **Host and workstations:** host health and certificate fingerprint, connected PCs (block or allow), run the self-check.
- **This PC** (your name menu): counter number, receipt printer and width, A4 printer, automatic receipt printing, cash drawer, compact layout.
