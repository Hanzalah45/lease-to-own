// A local stand-in for Intuit (QuickBooks Online), for trying the QuickBooks sync in a browser without a real QuickBooks
// company. It speaks only the parts of the real API the portal uses:
//
//   OAuth consent page, authorization-code and refresh-token exchange (the refresh token ROTATES on every refresh, like
//   Intuit's), token revoke, the query endpoint (accounts, journal entries by DocNumber), account create, journal entry create
//   (balanced? accounts real and active? period closed? requestid repeats answered with the original result?).
//
// Open http://localhost:8020/ for a live view of what "QuickBooks" holds: every journal entry with its lines, and a trial
// balance per account. Failure switches (all plain GET links so they can be tried from the address bar):
//   /__control?ttl=90                 access tokens last 90 seconds (the portal refreshes a minute early)
//   /__control?closedThrough=2026-10-10   entries dated on or before this day are refused (a closed period)
//   /__control?down=1  /  down=0      every API call answers 503
//   /__control?revokeRefresh=1        the current refresh token stops working (the connection must be redone)
//   /__control?loseReply=1            the next journal entry is stored but the reply is cut (an unknown outcome)
//   /__reset                          back to an empty company
//
// Run:  node tests/fake-intuit/server.mjs     (port 8020, or PORT=...)
// Point the portal at it with these in backend/.env:
//   QUICKBOOKS_CLIENT_ID=fake-client-id  QUICKBOOKS_CLIENT_SECRET=fake-client-secret
//   QUICKBOOKS_REDIRECT_URI=http://localhost:8010/api/quickbooks/callback
//   QUICKBOOKS_AUTHORIZE_URL=http://localhost:8020/connect/oauth2  QUICKBOOKS_TOKEN_URL=http://localhost:8020/oauth2/v1/tokens/bearer
//   QUICKBOOKS_REVOKE_URL=http://localhost:8020/v2/oauth2/tokens/revoke  QUICKBOOKS_API_BASE=http://localhost:8020

import http from "node:http";
import { randomBytes } from "node:crypto";

const PORT = Number(process.env.PORT ?? 8020);
const CLIENT_ID = process.env.FAKE_CLIENT_ID ?? "fake-client-id";
const CLIENT_SECRET = process.env.FAKE_CLIENT_SECRET ?? "fake-client-secret";
const REALM = "9130000000000001";

const SEED_ACCOUNTS = [
  ["Checking", "Bank"],
  ["Savings", "Bank"],
  ["Undeposited Funds", "Other Current Asset"],
  ["Accounts Receivable (A/R)", "Accounts Receivable"],
  ["Sales Tax Payable", "Other Current Liability"],
  ["Services", "Income"],
  ["Sales of Product Income", "Income"],
  ["Uncategorized Income", "Income"],
  ["Interest Earned", "Other Income"],
  ["Bank Charges", "Expense"],
  ["Rent Expense", "Expense"],
  ["Opening Balance Equity", "Equity"],
];

let state;

function reset() {
  state = {
    nextId: 1,
    accounts: [],
    entries: [],
    requests: new Map(), // requestid -> stored response
    codes: new Map(), // authorization code -> state
    accessTokens: new Map(), // token -> expiry ms
    refreshToken: null,
    ttl: 3600,
    closedThrough: null,
    down: false,
    loseReply: false,
    log: [],
  };
  for (const [name, type] of SEED_ACCOUNTS) addAccount(name, type);
}

function addAccount(name, type) {
  const account = {
    Id: String(state.nextId++),
    Name: name,
    FullyQualifiedName: name,
    AccountType: type,
    Active: true,
    Classification: ["Bank", "Other Current Asset", "Accounts Receivable"].includes(type)
      ? "Asset"
      : type.includes("Liability")
        ? "Liability"
        : type === "Equity"
          ? "Equity"
          : type.includes("Income")
            ? "Revenue"
            : "Expense",
  };
  state.accounts.push(account);
  return account;
}

function note(text) {
  state.log.unshift(`${new Date().toISOString().slice(11, 19)}  ${text}`);
  state.log.length = Math.min(state.log.length, 40);
}

function fault(res, status, message, detail, code, type = "ValidationFault") {
  send(res, status, { Fault: { Error: [{ Message: message, Detail: detail, code: String(code) }], type }, time: new Date().toISOString() });
}

function send(res, status, body, headers = {}) {
  const text = typeof body === "string" ? body : JSON.stringify(body);
  res.writeHead(status, { "Content-Type": typeof body === "string" ? "text/html; charset=utf-8" : "application/json", ...headers });
  res.end(text);
}

async function readBody(req) {
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  return Buffer.concat(chunks).toString("utf8");
}

function basicAuthOk(req) {
  const header = req.headers.authorization ?? "";
  if (!header.startsWith("Basic ")) return false;
  const [id, secret] = Buffer.from(header.slice(6), "base64").toString().split(":");
  return id === CLIENT_ID && secret === CLIENT_SECRET;
}

function issueTokens() {
  const access = `fake-access-${randomBytes(6).toString("hex")}`;
  const refresh = `fake-refresh-${randomBytes(6).toString("hex")}`;
  state.accessTokens.set(access, Date.now() + state.ttl * 1000);
  state.refreshToken = refresh; // the previous one is now worthless
  return { access_token: access, refresh_token: refresh, expires_in: state.ttl, x_refresh_token_expires_in: 8726400, token_type: "bearer" };
}

function bearerOk(req) {
  const token = (req.headers.authorization ?? "").replace(/^Bearer /, "");
  const expiry = state.accessTokens.get(token);
  return expiry !== undefined && expiry > Date.now();
}

const money = (n) => (Math.round(n * 100) / 100).toFixed(2);

function balances() {
  const totals = new Map();
  for (const entry of state.entries) {
    for (const line of entry.Line) {
      const d = line.JournalEntryLineDetail;
      const sign = d.PostingType === "Debit" ? 1 : -1;
      totals.set(d.AccountRef.value, (totals.get(d.AccountRef.value) ?? 0) + sign * line.Amount);
    }
  }
  return totals;
}

function page() {
  const totals = balances();
  const accountName = (id) => state.accounts.find((a) => a.Id === id)?.Name ?? `#${id}`;
  const esc = (s) => String(s ?? "").replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[c]);

  const entries = state.entries
    .slice()
    .reverse()
    .map((e) => {
      const debit = e.Line.filter((l) => l.JournalEntryLineDetail.PostingType === "Debit").reduce((s, l) => s + l.Amount, 0);
      const credit = e.Line.filter((l) => l.JournalEntryLineDetail.PostingType === "Credit").reduce((s, l) => s + l.Amount, 0);
      const rows = e.Line.map(
        (l) => `<tr><td>${esc(accountName(l.JournalEntryLineDetail.AccountRef.value))}</td><td class="n">${l.JournalEntryLineDetail.PostingType === "Debit" ? money(l.Amount) : ""}</td><td class="n">${l.JournalEntryLineDetail.PostingType === "Credit" ? money(l.Amount) : ""}</td><td class="d">${esc(l.Description)}</td></tr>`,
      ).join("");
      return `<section class="entry" data-doc="${esc(e.DocNumber)}"><h3>Journal entry ${esc(e.DocNumber)} <small>QuickBooks #${esc(e.Id)} - dated ${esc(e.TxnDate)} - ${money(debit) === money(credit) ? "balanced" : "NOT BALANCED"}</small></h3><p class="note">${esc(e.PrivateNote)}</p><table><thead><tr><th>Account</th><th class="n">Debit</th><th class="n">Credit</th><th>Description</th></tr></thead><tbody>${rows}</tbody><tfoot><tr><td>Total</td><td class="n">${money(debit)}</td><td class="n">${money(credit)}</td><td></td></tr></tfoot></table></section>`;
    })
    .join("");

  const trial = state.accounts
    .map((a) => {
      const t = totals.get(a.Id) ?? 0;
      return `<tr data-account="${esc(a.Name)}"><td>${esc(a.Name)}</td><td>${esc(a.AccountType)}</td><td class="n">${t > 0 ? money(t) : ""}</td><td class="n">${t < 0 ? money(-t) : ""}</td></tr>`;
    })
    .join("");
  const sum = [...totals.values()].reduce((s, v) => s + v, 0);

  return `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="5"><title>Fake QuickBooks</title><style>
  body{font:14px/1.45 system-ui,sans-serif;margin:24px;color:#1b1f23;background:#f6f7f8} h1{margin:0 0 4px;font-size:20px} h2{margin:28px 0 8px;font-size:15px;text-transform:uppercase;letter-spacing:.05em;color:#555}
  .bar{padding:10px 14px;background:#fff;border:1px solid #d8dde1;border-radius:8px;margin:12px 0} .entry{background:#fff;border:1px solid #d8dde1;border-radius:8px;padding:10px 14px;margin:10px 0}
  .entry h3{margin:0;font-size:14px} .entry small{color:#667;font-weight:400} .note{margin:2px 0 8px;color:#667;font-size:12px}
  table{border-collapse:collapse;width:100%} th,td{padding:4px 8px;border-bottom:1px solid #eceff1;text-align:left} th{font-size:11px;text-transform:uppercase;color:#778} .n{text-align:right;font-variant-numeric:tabular-nums} .d{color:#667;font-size:12px}
  tfoot td{font-weight:600;border-top:1px solid #99a;border-bottom:0} code{background:#eef;padding:1px 5px;border-radius:4px} pre{background:#fff;border:1px solid #d8dde1;border-radius:8px;padding:10px;max-height:220px;overflow:auto;font-size:12px}
  </style></head><body><h1>Fake QuickBooks Online</h1><p>A local stand-in for Intuit. Company <code>${REALM}</code>. This page refreshes every 5 seconds.</p>
  <div class="bar">Access tokens last <b>${state.ttl}s</b> &middot; closed through <b>${esc(state.closedThrough ?? "never")}</b> &middot; service <b>${state.down ? "DOWN (503)" : "up"}</b> &middot; connected: <b>${state.refreshToken ? "yes" : "no"}</b> &middot; <b data-count="entries">${state.entries.length}</b> journal entries &middot; debits minus credits overall: <b>${money(sum)}</b></div>
  <div class="bar">Controls: <a href="/__control?ttl=90">ttl 90s</a> &middot; <a href="/__control?ttl=3600">ttl 1h</a> &middot; <a href="/__control?closedThrough=2026-10-10">close through 2026-10-10</a> &middot; <a href="/__control?closedThrough=">reopen</a> &middot; <a href="/__control?down=1">go down</a> &middot; <a href="/__control?down=0">come back</a> &middot; <a href="/__control?loseReply=1">lose the next reply</a> &middot; <a href="/__control?revokeRefresh=1">revoke refresh token</a> &middot; <a href="/__reset">reset everything</a></div>
  <h2>Trial balance</h2><table><thead><tr><th>Account</th><th>Type</th><th class="n">Debit</th><th class="n">Credit</th></tr></thead><tbody>${trial}</tbody></table>
  <h2>Journal entries (newest first)</h2>${entries || "<p>None yet.</p>"}
  <h2>Requests</h2><pre>${esc(state.log.join("\n"))}</pre></body></html>`;
}

function handleQuery(res, url) {
  const sql = url.searchParams.get("query") ?? "";
  let m;
  if ((m = /from\s+JournalEntry\s+where\s+DocNumber\s*=\s*'([^']+)'/i.exec(sql))) {
    const found = state.entries.filter((e) => e.DocNumber === m[1]);
    return send(res, 200, { QueryResponse: found.length ? { JournalEntry: found, startPosition: 1, maxResults: found.length } : {}, time: new Date().toISOString() });
  }
  if ((m = /from\s+Account\s+where\s+Name\s*=\s*'([^']+)'/i.exec(sql))) {
    const found = state.accounts.filter((a) => a.Name === m[1]);
    return send(res, 200, { QueryResponse: found.length ? { Account: found, startPosition: 1, maxResults: found.length } : {}, time: new Date().toISOString() });
  }
  if (/from\s+Account/i.test(sql)) {
    const start = Number(/startposition\s+(\d+)/i.exec(sql)?.[1] ?? 1);
    const max = Number(/maxresults\s+(\d+)/i.exec(sql)?.[1] ?? 100);
    const active = state.accounts.filter((a) => a.Active);
    const page = active.slice(start - 1, start - 1 + max);
    return send(res, 200, { QueryResponse: page.length ? { Account: page, startPosition: start, maxResults: page.length } : {}, time: new Date().toISOString() });
  }
  return fault(res, 400, "Invalid query", `The fake does not understand: ${sql}`, 4000);
}

function handleAccountCreate(res, body) {
  if (!body.Name || (!body.AccountType && !body.AccountSubType)) {
    return fault(res, 400, "Required param missing, need to supply the required value for the API", "Required parameter Name or AccountType is missing in the request", 2020);
  }
  if (state.accounts.some((a) => a.Name.toLowerCase() === String(body.Name).toLowerCase())) {
    return fault(res, 400, "Duplicate Name Exists Error", `The name supplied already exists. : Another account is already using this name=${body.Name}`, 6240);
  }
  const account = addAccount(body.Name, body.AccountType);
  note(`created account ${account.Name} (${account.AccountType}) as #${account.Id}`);
  send(res, 200, { Account: account, time: new Date().toISOString() });
}

function handleJournalEntry(res, url, body) {
  const requestId = url.searchParams.get("requestid");
  if (requestId && state.requests.has(requestId)) {
    note(`journal entry ${body.DocNumber}: repeat of request ${requestId}, answering with the original`);
    return send(res, 200, state.requests.get(requestId));
  }
  const lines = body.Line ?? [];
  if (lines.length < 2) return fault(res, 400, "Business Validation Error", "A journal entry needs at least two lines", 6000);
  let debit = 0;
  let credit = 0;
  for (const line of lines) {
    const detail = line.JournalEntryLineDetail;
    if (line.DetailType !== "JournalEntryLineDetail" || !detail?.PostingType || !detail?.AccountRef?.value) {
      return fault(res, 400, "Required param missing, need to supply the required value for the API", "Each line needs DetailType, PostingType and AccountRef", 2020);
    }
    const account = state.accounts.find((a) => a.Id === String(detail.AccountRef.value));
    if (!account) return fault(res, 400, "Invalid Reference Id", `Invalid Reference Id : Accounts element id ${detail.AccountRef.value} is not found`, 2500);
    if (!account.Active) return fault(res, 400, "Invalid Reference Id", `Invalid Reference Id : Accounts element id ${account.Id} (${account.Name}) is inactive`, 2500);
    if (!(line.Amount > 0)) return fault(res, 400, "Business Validation Error", "Line amounts must be greater than zero", 6000);
    if (detail.PostingType === "Debit") debit += line.Amount;
    else credit += line.Amount;
  }
  if (money(debit) !== money(credit)) {
    return fault(res, 400, "Business Validation Error", `Transaction is out of balance: debits ${money(debit)} do not equal credits ${money(credit)}`, 6000);
  }
  if (!body.TxnDate || !/^\d{4}-\d{2}-\d{2}$/.test(body.TxnDate)) {
    return fault(res, 400, "Required param missing, need to supply the required value for the API", "TxnDate must be YYYY-MM-DD", 2020);
  }
  if (state.closedThrough && body.TxnDate <= state.closedThrough) {
    return fault(res, 400, "Business Validation Error", `Account Period Closed: the books are closed through ${state.closedThrough}`, 6200);
  }

  const entry = { ...body, Id: String(state.nextId++), SyncToken: "0", MetaData: { CreateTime: new Date().toISOString() } };
  state.entries.push(entry);
  const response = { JournalEntry: entry, time: new Date().toISOString() };
  if (requestId) state.requests.set(requestId, response);
  note(`journal entry ${entry.DocNumber} created as #${entry.Id} for ${money(debit)}`);

  if (state.loseReply) {
    state.loseReply = false;
    note(`reply for ${entry.DocNumber} was lost on purpose`);
    return res.destroy();
  }
  send(res, 200, response);
}

reset();

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://localhost:${PORT}`);
  const path = url.pathname;

  try {
    if (req.method === "GET" && path === "/") return send(res, 200, page());
    if (req.method === "GET" && path === "/__state") {
      return send(res, 200, { entries: state.entries, accounts: state.accounts, balances: Object.fromEntries(balances()), refreshToken: state.refreshToken });
    }
    if (path === "/__reset") {
      reset();
      res.writeHead(302, { Location: "/" });
      return res.end();
    }
    if (path === "/__control") {
      if (url.searchParams.has("ttl")) state.ttl = Number(url.searchParams.get("ttl"));
      if (url.searchParams.has("closedThrough")) state.closedThrough = url.searchParams.get("closedThrough") || null;
      if (url.searchParams.has("down")) state.down = url.searchParams.get("down") === "1";
      if (url.searchParams.has("loseReply")) state.loseReply = url.searchParams.get("loseReply") === "1";
      if (url.searchParams.get("revokeRefresh") === "1") state.refreshToken = `revoked-${randomBytes(4).toString("hex")}`;
      note(`control: ${url.search}`);
      res.writeHead(302, { Location: "/" });
      return res.end();
    }

    // ---- OAuth ----
    if (req.method === "GET" && path === "/connect/oauth2") {
      const redirect = url.searchParams.get("redirect_uri") ?? "";
      const stateParam = url.searchParams.get("state") ?? "";
      if (url.searchParams.get("client_id") !== CLIENT_ID) return send(res, 400, "<h1>Unknown client_id</h1>");
      const code = `fake-code-${randomBytes(5).toString("hex")}`;
      state.codes.set(code, stateParam);
      const ok = `${redirect}?${new URLSearchParams({ code, state: stateParam, realmId: REALM })}`;
      const denied = `${redirect}?${new URLSearchParams({ error: "access_denied", state: stateParam })}`;
      return send(
        res,
        200,
        `<!doctype html><title>Fake Intuit</title><body style="font:16px system-ui;max-width:520px;margin:60px auto"><h1>Fake Intuit sign-in</h1><p><b>Prostart Leasing</b> wants to connect to the QuickBooks company <b>Fake Company Inc.</b> (<code>${REALM}</code>) and use the <code>${esc2(url.searchParams.get("scope"))}</code> permission.</p><p><a id="authorize" href="${esc2(ok)}" style="display:inline-block;padding:10px 18px;background:#2ca01c;color:#fff;border-radius:6px;text-decoration:none">Connect</a> &nbsp; <a id="deny" href="${esc2(denied)}">Cancel</a></p></body>`,
      );
    }
    if (req.method === "POST" && path === "/oauth2/v1/tokens/bearer") {
      if (!basicAuthOk(req)) return send(res, 401, { error: "invalid_client" });
      const form = new URLSearchParams(await readBody(req));
      if (form.get("grant_type") === "authorization_code") {
        if (!state.codes.has(form.get("code"))) return send(res, 400, { error: "invalid_grant" });
        state.codes.delete(form.get("code"));
        note("authorization code exchanged for tokens");
        return send(res, 200, issueTokens());
      }
      if (form.get("grant_type") === "refresh_token") {
        if (form.get("refresh_token") !== state.refreshToken) {
          note("refresh refused: not the latest refresh token");
          return send(res, 400, { error: "invalid_grant" });
        }
        note("access token refreshed (the refresh token rotated)");
        return send(res, 200, issueTokens());
      }
      return send(res, 400, { error: "unsupported_grant_type" });
    }
    if (req.method === "POST" && path === "/v2/oauth2/tokens/revoke") {
      if (!basicAuthOk(req)) return send(res, 401, { error: "invalid_client" });
      state.refreshToken = null;
      state.accessTokens.clear();
      note("connection revoked");
      return send(res, 200, "");
    }

    // ---- Accounting API ----
    const api = /^\/v3\/company\/([^/]+)\/([a-z]+)$/.exec(path);
    if (api) {
      if (state.down) return send(res, 503, "Service Unavailable");
      if (!bearerOk(req)) {
        note(`${req.method} ${api[2]}: 401, the access token is not valid`);
        return fault(res, 401, "message=AuthenticationFailed; errorCode=003200; statusCode=401", "The access token is invalid or expired", 3200, "AUTHENTICATION");
      }
      if (api[1] !== REALM) return fault(res, 400, "Invalid company", `Unknown company ${api[1]}`, 4000);
      if (req.method === "GET" && api[2] === "query") return handleQuery(res, url);
      const text = await readBody(req);
      const body = text ? JSON.parse(text) : {};
      if (req.method === "POST" && api[2] === "account") return handleAccountCreate(res, body);
      if (req.method === "POST" && api[2] === "journalentry") return handleJournalEntry(res, url, body);
    }

    send(res, 404, { error: "not found", path });
  } catch (error) {
    console.error(error);
    send(res, 500, { error: String(error) });
  }
});

function esc2(s) {
  return String(s ?? "").replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[c]);
}

server.listen(PORT, () => console.log(`Fake QuickBooks listening on http://localhost:${PORT}`));
