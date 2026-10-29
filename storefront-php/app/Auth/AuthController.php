<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

use Pratyangi\Http\ApiException;
use Pratyangi\Http\Context;
use Pratyangi\Http\RateLimiter;
use Pratyangi\Http\Request;
use Pratyangi\Http\Response;

/** The account routes. JSON shapes are exactly the Node API's. */
final class AuthController
{
    public const COOKIE = 'pf_session';

    /**
     * @param \Closure():OtpService $otp builds the mobile sign-in service on first use (503 when mobile sign-in is not set up)
     * @param \Closure():AccountService $account builds the verify / link / reset service on first use (503 when codes are not set up)
     */
    public function __construct(
        private AuthService $auth,
        private bool $secureCookie,
        private ?\Closure $otp = null,
        private ?\Closure $account = null,
        private ?RateLimiter $limiter = null,
        private ?\Pratyangi\Support\Logger $logger = null,
        private ?GoogleVerifier $google = null,
    ) {
    }

    private function otp(): OtpService
    {
        if ($this->otp === null) {
            throw ApiException::unavailable('Mobile sign-in is not set up yet. Please sign in with your email.');
        }
        return ($this->otp)();
    }

    private function account(): AccountService
    {
        if ($this->account === null) {
            throw ApiException::unavailable('Codes are not set up yet. Please try again later.');
        }
        return ($this->account)();
    }

    /** The signed-in customer's id, or 401. */
    private function customerId(Context $context): int
    {
        $row = $context->customer();
        if ($row === null) {
            throw ApiException::unauthorized();
        }
        return (int) $row['id'];
    }

    public function signup(Request $request, Context $context): Response
    {
        $started = $this->auth->signUp(Validator::signup($request->jsonBody()), $request->header('user-agent'));
        // signed in at once; the email code is asked only for forgot password, change email and before the first order
        return Response::json(['user' => $started['customer']], 201)
            ->setCookie(self::COOKIE, $started['token'], (int) $started['expiresAt'], $this->secureCookie);
    }

    /** POST /auth/google {credential}: 200 + cookie, an existing email signs in (and is linked), a new one is created. */
    public function google(Request $request, Context $context): Response
    {
        if ($this->google === null) {
            throw ApiException::unavailable('Google sign-in is not set up on the server yet.');
        }
        $input = Validator::googleCredential($request->jsonBody());
        $started = $this->auth->googleSignIn($this->google->verify($input['credential']), $request->header('user-agent'));
        return Response::json(['user' => $started['customer']])
            ->setCookie(self::COOKIE, $started['token'], (int) $started['expiresAt'], $this->secureCookie);
    }

    public function login(Request $request, Context $context): Response
    {
        $input = Validator::login($request->jsonBody());
        // per email address as well as per client address, counted for every address so the count says nothing about accounts
        $context->secondary(fn (Context $c) => $this->limiter?->consumeSubject('login_email', $input['email'], $c));
        $started = $this->auth->signIn($input, $request->header('user-agent'));
        return Response::json(['user' => $started['customer']])
            ->setCookie(self::COOKIE, $started['token'], (int) $started['expiresAt'], $this->secureCookie);
    }

    /** PHP-only: sends a one-time code by SMS: purpose 'login' (known numbers only, else 404) or 'signup' (new numbers only, else 409). */
    public function otpRequest(Request $request, Context $context, string $purpose = 'login'): Response
    {
        $input = Validator::otpRequest($request->jsonBody());
        try {
            $otp = $this->otp();
        } catch (ApiException $e) {
            if ($e->status === 503) {
                $this->logger?->channel('auth')->warn('otp request rejected', [
                    'channel' => 'mobile',
                    'reason' => 'gateway_failure',
                    'mobile' => Mobile::mask($input['mobile']),
                    'outcome' => 'rejected',
                ]);
            }
            throw $e;
        }
        return Response::json($otp->request($input['mobile'], $context->clientIp(), $context, $purpose));
    }

    /** PHP-only ("Forgot email or mobile?"): the same answer for every number. */
    public function helpSms(Request $request, Context $context): Response
    {
        $input = Validator::otpRequest($request->jsonBody());
        $this->otp()->sendEmailHint($input['mobile'], $context);
        return Response::json(['message' => "If this number has an account, we've sent an SMS."]);
    }

    /** PHP-only: 'login' signs the account in and never creates one; 'signup' creates it (no name, no email asked) and signs in. */
    public function otpVerify(Request $request, Context $context, string $purpose = 'login'): Response
    {
        $input = Validator::otpVerify($request->jsonBody()); // first: a malformed body gets the one refusal whatever the settings are
        $result = $this->otp()->verify($input, $request->header('user-agent'), $purpose);
        return Response::json(['user' => $result['customer']], $result['created'] ? 201 : 200)
            ->setCookie(self::COOKIE, $result['token'], (int) $result['expiresAt'], $this->secureCookie);
    }

    /** Works with or without a cookie; always clears it. */
    public function logout(Request $request, Context $context): Response
    {
        $this->auth->signOut($request->cookie(self::COOKIE));
        return Response::noContent()->setCookie(self::COOKIE, '', 0, $this->secureCookie);
    }

    public function me(Request $request, Context $context): Response
    {
        $row = $context->customer();
        if ($row === null) {
            throw ApiException::unauthorized();
        }
        return Response::json(['user' => PublicCustomer::fromRow($row)]);
    }

    /** The old link ({token}, no sign-in needed) or, for the signed-in customer, the 6-digit code ({code}). */
    public function verifyEmail(Request $request, Context $context): Response
    {
        $body = $request->jsonBody();
        if (is_array($body) && is_string($body['token'] ?? null) && $body['token'] !== '') {
            $input = Validator::verifyEmail($body);
            return Response::json(['customer' => $this->auth->verifyEmail($input['token'])]);
        }
        $customerId = $this->customerId($context);
        $input = Validator::codeOnly($body);
        return Response::json(['customer' => $this->account()->verifyEmail($customerId, $input['code'])]);
    }

    /** Sends the signed-in customer's own, unverified email a new 6-digit code. */
    public function resend(Request $request, Context $context): Response
    {
        $result = $this->account()->requestEmailVerify($this->customerId($context), $context->clientIp(), $context);
        if (isset($result['alreadyVerified'])) {
            return Response::json(['message' => 'Your email is already verified.']);
        }
        return Response::json(['message' => 'We have sent a new 6-digit code to your email.'] + $result);
    }

    /** PHP-only: a code to add a mobile number to the signed-in account. */
    public function mobileRequest(Request $request, Context $context): Response
    {
        $customerId = $this->customerId($context);
        $input = Validator::otpRequest($request->jsonBody());
        return Response::json($this->account()->requestMobileLink($customerId, $input['mobile'], $context->clientIp(), $context));
    }

    /** PHP-only: stores the number on the signed-in account once the code is right. */
    public function mobileVerify(Request $request, Context $context): Response
    {
        $customerId = $this->customerId($context);
        $input = Validator::mobileCode($request->jsonBody());
        return Response::json(['user' => $this->account()->verifyMobileLink($customerId, $input['mobile'], $input['code'])]);
    }

    /** PHP-only: a code to add an email address to the signed-in account. */
    public function emailLinkRequest(Request $request, Context $context): Response
    {
        $customerId = $this->customerId($context);
        $input = Validator::emailOnly($request->jsonBody());
        return Response::json($this->account()->requestEmailLink($customerId, $input['email'], $context->clientIp(), $context));
    }

    /** PHP-only: stores the email address on the signed-in account (verified) once the code is right. */
    public function emailLinkVerify(Request $request, Context $context): Response
    {
        $customerId = $this->customerId($context);
        $input = Validator::emailCode($request->jsonBody());
        return Response::json(['user' => $this->account()->verifyEmailLink($customerId, $input['email'], $input['code'])]);
    }

    /** PHP-only: the name of an account that has none (a mobile sign-up asks for none). */
    public function profileName(Request $request, Context $context): Response
    {
        $customerId = $this->customerId($context);
        $input = Validator::profileName($request->jsonBody());
        return Response::json(['user' => $this->account()->setName($customerId, $input['name'])]);
    }

    public function forgot(Request $request, Context $context): Response
    {
        $input = Validator::forgot($request->jsonBody());
        $this->account()->requestReset($input['channel'], $input['target'], $context->clientIp(), $context);
        return Response::json(['message' => $input['channel'] === 'mobile'
            ? 'If an account matches, we have sent a 6-digit code by SMS.'
            : 'If an account matches, we have emailed a 6-digit code.']);
    }

    public function reset(Request $request, Context $context): Response
    {
        $input = Validator::reset($request->jsonBody());
        // after the form checks, so a rejected form does not use one of the 5 tries (the route has no bucket of its own)
        $this->limiter?->consume('reset', $context->clientIp(), $context);
        $this->account()->reset($input['channel'], $input['target'], $input['code'], $input['password']);
        return Response::noContent();
    }
}
