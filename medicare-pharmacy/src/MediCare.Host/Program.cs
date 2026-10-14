using MediCare.Host.Domain;
using MediCare.Host.Infrastructure;
using MediCare.Host.Modules;
using MediCare.Host.Security;
using Serilog;

DapperSetup.Configure();

var builder = WebApplication.CreateBuilder(new WebApplicationOptions { Args = args, ContentRootPath = AppContext.BaseDirectory });
builder.Host.UseWindowsService(o => o.ServiceName = "MediCarePharmacyHost");

var options = builder.Configuration.GetSection("MediCare").Get<MediCareOptions>() ?? new MediCareOptions();
var dataDir = options.ResolveDataDir();
Directory.CreateDirectory(dataDir);

builder.Host.UseSerilog((ctx, cfg) => cfg
    .MinimumLevel.Information()
    .MinimumLevel.Override("Microsoft", Serilog.Events.LogEventLevel.Warning)
    .Enrich.With(new RedactionEnricher())
    .WriteTo.Console()
    .WriteTo.File(Path.Combine(dataDir, "logs", "host-.log"), rollingInterval: RollingInterval.Day, retainedFileCountLimit: 14));

builder.Services.ConfigureHttpJsonOptions(o =>
{
    o.SerializerOptions.PropertyNamingPolicy = System.Text.Json.JsonNamingPolicy.CamelCase;
    o.SerializerOptions.PropertyNameCaseInsensitive = true;
});
builder.Services.AddSingleton(options);
builder.Services.AddSingleton(new Db(dataDir));
builder.Services.AddSingleton<EventBus>();
builder.Services.AddSingleton<SettingsService>();
builder.Services.AddSingleton<AuthService>();
builder.Services.AddSingleton<ApprovalService>();
builder.Services.AddSingleton<CatalogueService>();
builder.Services.AddSingleton<ImportService>();
builder.Services.AddSingleton<PartyService>();
builder.Services.AddSingleton<PurchaseService>();
builder.Services.AddSingleton<StockService>();
builder.Services.AddSingleton<SalesService>();
builder.Services.AddSingleton<ReturnService>();
builder.Services.AddSingleton<AccountService>();
builder.Services.AddSingleton<ReportService>();
builder.Services.AddSingleton<BackupService>();
builder.Services.AddSingleton<SelfCheckService>();
builder.Services.AddSingleton<CertificateInfo>(_ => options.UseHttps ? Certificates.LoadOrCreate(dataDir) : new CertificateInfo(null, ""));
builder.Services.AddHostedService<JobsService>();
// Reports, lists and ledgers are large JSON; gzip at the fastest level cuts LAN transfer about tenfold.
builder.Services.AddResponseCompression(o =>
{
    o.EnableForHttps = true;
    o.Providers.Add<Microsoft.AspNetCore.ResponseCompression.GzipCompressionProvider>();
    o.MimeTypes = new[] { "application/json", "text/csv", "text/plain" };
});
builder.Services.Configure<Microsoft.AspNetCore.ResponseCompression.GzipCompressionProviderOptions>(o => o.Level = System.IO.Compression.CompressionLevel.Fastest);

if (options.UseHttps)
{
    var cert = Certificates.LoadOrCreate(dataDir);
    builder.WebHost.ConfigureKestrel(k => k.Listen(System.Net.IPAddress.Parse(options.BindAddress), options.Port, l => l.UseHttps(cert.Certificate!)));
}
else
{
    builder.WebHost.ConfigureKestrel(k => k.Listen(System.Net.IPAddress.Parse(options.BindAddress), options.Port));
}

var app = builder.Build();

var db = app.Services.GetRequiredService<Db>();
db.Migrate();
Seeder.Run(db);
app.Services.GetRequiredService<SettingsService>().Load();

// Only GET responses are compressed. Session tokens come from POST /auth/login and are never compressed, so response size cannot leak them (BREACH).
app.UseWhen(ctx => HttpMethods.IsGet(ctx.Request.Method), branch => branch.UseResponseCompression());
app.UseMiddleware<ErrorMiddleware>();
app.UseRouting();
app.UseMiddleware<AuthMiddleware>();

SetupAuthModule.Map(app);
AdminModule.Map(app);
CatalogueModule.Map(app);
PartyModule.Map(app);
PurchaseModule.Map(app);
StockModule.Map(app);
SalesModule.Map(app);
AccountModule.Map(app);
ReportModule.Map(app);
BackupModule.Map(app);

Log.Information("MediCare host started. Data folder {DataDir}, port {Port}, https {Https}", dataDir, options.Port, options.UseHttps);
app.Run();

public partial class Program;
