[CmdletBinding()]
param(
	[Parameter(Mandatory = $true)]
	[int]$ProductId,

	[Parameter(Mandatory = $false)]
	[ValidateSet('create', 'update')]
	[string]$WebhookType = 'create',

	[Parameter(Mandatory = $false)]
	[string]$BaseUrl = 'http://wordpress.test',

	[Parameter(Mandatory = $false)]
	[int]$Concurrency = 4,

	[Parameter(Mandatory = $false)]
	[int]$TimeoutSeconds = 30,

	[Parameter(Mandatory = $false)]
	[string]$WebhookKey = '',

	[Parameter(Mandatory = $false)]
	[string]$AuthHeaderValue = '',

	[Parameter(Mandatory = $false)]
	[string]$PayloadPath = '',

	[Parameter(Mandatory = $false)]
	[string]$StoreId = '',

	[Parameter(Mandatory = $false)]
	[string]$Producer = '',

	[Parameter(Mandatory = $false)]
	[int]$ChannelId = 0,

	[Parameter(Mandatory = $false)]
	[switch]$DryRun
)

Set-StrictMode -Version Latest

function Get-WebhookName {
	param(
		[Parameter(Mandatory = $true)]
		[string]$Type
	)

	if ( $Type -eq 'update' ) {
		return 'product_update'
	}

	return 'product_create'
}

function Get-WebhookScope {
	param(
		[Parameter(Mandatory = $true)]
		[string]$Type
	)

	if ( $Type -eq 'update' ) {
		return 'store/product/updated'
	}

	return 'store/product/created'
}

function Get-AuthHeaderValue {
	param(
		[Parameter(Mandatory = $true)]
		[string]$WebhookName,

		[Parameter(Mandatory = $false)]
		[string]$WebhookKey,

		[Parameter(Mandatory = $false)]
		[string]$ProvidedHeader
	)

	if ( -not [string]::IsNullOrWhiteSpace( $ProvidedHeader ) ) {
		return $ProvidedHeader
	}

	if ( [string]::IsNullOrWhiteSpace( $WebhookKey ) ) {
		throw 'Provide -WebhookKey (bigcommerce_webhook_key) or -AuthHeaderValue.'
	}

	$input = "$WebhookKey$WebhookName"
	$bytes = [System.Text.Encoding]::UTF8.GetBytes( $input )
	$hash  = [System.Security.Cryptography.MD5]::Create().ComputeHash( $bytes )
	return ( [System.BitConverter]::ToString( $hash ) -replace '-', '' ).ToLowerInvariant()
}

function New-WebhookPayload {
	param(
		[Parameter(Mandatory = $true)]
		[int]$ProductId,

		[Parameter(Mandatory = $true)]
		[string]$Scope,

		[Parameter(Mandatory = $false)]
		[string]$StoreId,

		[Parameter(Mandatory = $false)]
		[string]$Producer,

		[Parameter(Mandatory = $false)]
		[int]$ChannelId
	)

	$payload = [ordered]@{
		producer   = $Producer
		hash       = [guid]::NewGuid().ToString('N')
		created_at = [int][DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
		store_id   = $StoreId
		scope      = $Scope
		data       = @{
			id = $ProductId
		}
	}

	if ( $ChannelId -gt 0 ) {
		$payload.data.channel_id = $ChannelId
	}

	foreach ( $key in @('producer', 'store_id') ) {
		if ( [string]::IsNullOrWhiteSpace( $payload[$key] ) ) {
			$payload.Remove( $key )
		}
	}

	return $payload | ConvertTo-Json -Depth 6
}

function Invoke-WebhookFanout {
	param(
		[Parameter(Mandatory = $true)]
		[int]$ProductId,

		[Parameter(Mandatory = $true)]
		[string]$WebhookType,

		[Parameter(Mandatory = $true)]
		[string]$BaseUrl,

		[Parameter(Mandatory = $true)]
		[int]$Concurrency,

		[Parameter(Mandatory = $true)]
		[int]$TimeoutSeconds,

		[Parameter(Mandatory = $true)]
		[string]$AuthHeaderValue,

		[Parameter(Mandatory = $true)]
		[string]$PayloadJson,

		[Parameter(Mandatory = $true)]
		[switch]$DryRun
	)

	$webhookName = Get-WebhookName -Type $WebhookType
	$endpoint    = $BaseUrl.TrimEnd( '/' ) + "/bigcommerce/webhook/$webhookName"

	if ( $DryRun ) {
		Write-Host "Dry run: would POST to $endpoint" -ForegroundColor Yellow
		Write-Host "Auth header: X-WP-BigCommerce-Webhook-Auth-Header" -ForegroundColor Yellow
		Write-Host "Payload:" -ForegroundColor Yellow
		Write-Host $PayloadJson
		return
	}

	$httpClientAvailable = $true
	try {
		Add-Type -AssemblyName System.Net.Http -ErrorAction Stop
		$null = [System.Net.Http.HttpClient]
	} catch {
		$httpClientAvailable = $false
	}

	if ( $httpClientAvailable ) {
		[System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12
		$handler = [System.Net.Http.HttpClientHandler]::new()
		$handler.AllowAutoRedirect = $false
		$client = [System.Net.Http.HttpClient]::new( $handler )
		$client.Timeout = [TimeSpan]::FromSeconds( $TimeoutSeconds )

		$tasks = @()
		for ( $i = 1; $i -le $Concurrency; $i++ ) {
			$request = [System.Net.Http.HttpRequestMessage]::new( [System.Net.Http.HttpMethod]::Post, $endpoint )
			$request.Headers.Add( 'X-WP-BigCommerce-Webhook-Auth-Header', $AuthHeaderValue )
			$request.Content = [System.Net.Http.StringContent]::new( $PayloadJson, [System.Text.Encoding]::UTF8, 'application/json' )
			$tasks += $client.SendAsync( $request )
		}

		try {
			[System.Threading.Tasks.Task]::WhenAll( $tasks ).GetAwaiter().GetResult() | Out-Null
		} catch {
			Write-Error "One or more requests failed: $($_.Exception.Message)"
		}

		$redirectTasks = @{}
		for ( $index = 0; $index -lt $tasks.Count; $index++ ) {
			$task = $tasks[$index]
			if ( $task.Status -ne [System.Threading.Tasks.TaskStatus]::RanToCompletion ) {
				continue
			}

			$response = $task.Result
			if ( $response.StatusCode -in 301, 302, 307, 308 -and $response.Headers.Location ) {
				$redirectUri = $response.Headers.Location.AbsoluteUri
				$redirectRequest = [System.Net.Http.HttpRequestMessage]::new( [System.Net.Http.HttpMethod]::Post, $redirectUri )
				$redirectRequest.Headers.Add( 'X-WP-BigCommerce-Webhook-Auth-Header', $AuthHeaderValue )
				$redirectRequest.Content = [System.Net.Http.StringContent]::new( $PayloadJson, [System.Text.Encoding]::UTF8, 'application/json' )
				$redirectTasks[$index] = $client.SendAsync( $redirectRequest )
			}
		}

		if ( $redirectTasks.Count -gt 0 ) {
			try {
				[System.Threading.Tasks.Task]::WhenAll( $redirectTasks.Values ).GetAwaiter().GetResult() | Out-Null
			} catch {
				Write-Error "One or more redirect requests failed: $($_.Exception.Message)"
			}
		}

		for ( $index = 0; $index -lt $tasks.Count; $index++ ) {
			$task = $tasks[$index]
			if ( $task.Status -eq [System.Threading.Tasks.TaskStatus]::RanToCompletion ) {
				$response = $task.Result
				if ( $redirectTasks.ContainsKey( $index ) ) {
					$response = $redirectTasks[$index].Result
					Write-Host "Request $($index + 1): redirected" -ForegroundColor Yellow
				}

				$body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
				Write-Host "Request $($index + 1): $([int]$response.StatusCode)" -ForegroundColor Green
				if ( -not [string]::IsNullOrWhiteSpace( $body ) ) {
					Write-Host $body
				}
			} else {
				Write-Host "Request $($index + 1): failed" -ForegroundColor Red
				if ( $task.Exception -and $task.Exception.InnerException ) {
					Write-Host $task.Exception.InnerException.Message
				} elseif ( $task.Exception ) {
					Write-Host $task.Exception.Message
				}
			}
		}

		return
	}

	$jobs = @()
	for ( $i = 1; $i -le $Concurrency; $i++ ) {
		$jobs += Start-Job -ScriptBlock {
			param( $jobEndpoint, $jobAuthHeader, $jobPayload, $jobTimeout )
			$params = @{
				Uri         = $jobEndpoint
				Method      = 'Post'
				Headers     = @{ 'X-WP-BigCommerce-Webhook-Auth-Header' = $jobAuthHeader }
				Body        = $jobPayload
				ContentType = 'application/json'
				TimeoutSec  = $jobTimeout
			}
			try {
				$response = Invoke-WebRequest @params
				return [pscustomobject]@{
					StatusCode = $response.StatusCode
					Content    = $response.Content
				}
			} catch {
				return [pscustomobject]@{
					Error = $_.Exception.Message
				}
			}
		} -ArgumentList $endpoint, $AuthHeaderValue, $PayloadJson, $TimeoutSeconds
	}

	Wait-Job -Job $jobs | Out-Null

	for ( $index = 0; $index -lt $jobs.Count; $index++ ) {
		$result = Receive-Job -Job $jobs[$index]
		if ( $null -ne $result.Error ) {
			Write-Host "Request $($index + 1): failed" -ForegroundColor Red
			Write-Host $result.Error
		} else {
			Write-Host "Request $($index + 1): $($result.StatusCode)" -ForegroundColor Green
			if ( -not [string]::IsNullOrWhiteSpace( $result.Content ) ) {
				Write-Host $result.Content
			}
		}
	}

	Remove-Job -Job $jobs | Out-Null
}

$webhookName   = Get-WebhookName -Type $WebhookType
$webhookScope  = Get-WebhookScope -Type $WebhookType
$authHeader    = Get-AuthHeaderValue -WebhookName $webhookName -WebhookKey $WebhookKey -ProvidedHeader $AuthHeaderValue

if ( -not [string]::IsNullOrWhiteSpace( $PayloadPath ) ) {
	if ( -not ( Test-Path -Path $PayloadPath ) ) {
		throw "PayloadPath not found: $PayloadPath"
	}

	$payloadJson = Get-Content -Path $PayloadPath -Raw
} else {
	$payloadJson = New-WebhookPayload -ProductId $ProductId -Scope $webhookScope -StoreId $StoreId -Producer $Producer -ChannelId $ChannelId
}

Invoke-WebhookFanout -ProductId $ProductId -WebhookType $WebhookType -BaseUrl $BaseUrl -Concurrency $Concurrency -TimeoutSeconds $TimeoutSeconds -AuthHeaderValue $authHeader -PayloadJson $payloadJson -DryRun:$DryRun
