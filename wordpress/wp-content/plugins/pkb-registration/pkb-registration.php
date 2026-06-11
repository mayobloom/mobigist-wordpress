<?php
/**
 * Plugin Name: PKB Registration
 * Description: Registration, email verification, login, and frontend account management for PKB.
 * Version: 0.2.35
 * Author: PKB
 * Text Domain: pkb-registration
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PKB_REGISTRATION_VERSION', '0.2.35');
define('PKB_REGISTRATION_FILE', __FILE__);
define('PKB_REGISTRATION_DIR', plugin_dir_path(__FILE__));
define('PKB_REGISTRATION_URL', plugin_dir_url(__FILE__));

final class PKB_Registration
{
    private const AVATAR_MAX_BYTES = 2097152;
    private const AVATAR_SIZE = 192;
    private const AVATAR_RATE_LIMIT = 5;
    private const AVATAR_RATE_WINDOW = 3600;
    private const AVATAR_RATE_COOLDOWN = 10;
    private const AUDIT_LOG_LIMIT = 200;
    private const FLASH_COOKIE = 'pkb_registration_flash';
    private const LEGAL_VERSION = '2026-06-10.3';

    private static ?PKB_Registration $instance = null;

    public static function instance(): PKB_Registration
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('init', [$this, 'ensure_pages']);
        add_action('init', [$this, 'handle_forms']);
        add_action('init', [$this, 'maybe_verify_email']);
        add_action('init', [$this, 'maybe_confirm_email_change']);
        add_action('init', [$this, 'schedule_cleanup']);
        add_action('init', [$this, 'configure_two_factor']);
        add_action('pkb_cleanup_unverified_users', [$this, 'cleanup_unverified_users']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'redirect_profile_from_admin'], 1);
        add_action('admin_init', [$this, 'redirect_non_staff_from_admin']);
        add_action('admin_bar_menu', [$this, 'replace_admin_bar_profile_link'], 80);
        add_action('pkb_before_user_account_delete', [$this, 'delete_user_avatar']);
        add_action('delete_user', [$this, 'delete_user_avatar']);
        add_action('two_factor_user_authenticated', [$this, 'record_two_factor_success']);
        add_filter('authenticate', [$this, 'block_unverified_login'], 25, 3);
        add_filter('authenticate', [$this, 'ensure_staff_two_factor'], 29, 3);
        add_filter('show_admin_bar', [$this, 'hide_admin_bar_for_members']);
        add_filter('get_avatar_data', [$this, 'filter_avatar_data'], 10, 2);
        add_filter('wp_nav_menu_items', [$this, 'append_account_nav'], 10, 2);
        add_filter('login_redirect', [$this, 'redirect_two_factor_revalidation'], 10, 3);
        add_filter('gettext', [$this, 'translate_two_factor_text'], 10, 3);
        add_filter('gettext_with_context', [$this, 'translate_two_factor_text_with_context'], 10, 4);
        add_filter('rest_request_after_callbacks', [$this, 'ensure_staff_two_factor_after_totp_rest'], 10, 3);
    }

    public static function activate(): void
    {
        self::create_roles();
        self::create_pages();
        self::set_default_options();
        self::schedule_cleanup_static();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('pkb_cleanup_unverified_users');
        flush_rewrite_rules();
    }

    private static function create_roles(): void
    {
        add_role('pkb_member', '일반회원', ['read' => true]);
        add_role('pkb_special_member', '특별회원', [
            'read' => true,
            'pkb_read_special' => true,
        ]);
        add_role('pkb_manager', '매니저', [
            'read' => true,
            'moderate_comments' => true,
            'edit_comment' => true,
            'pkb_read_special' => true,
            'pkb_moderate_members' => true,
        ]);

        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('pkb_read_special');
            $admin->add_cap('pkb_moderate_members');
        }
    }

    private static function create_pages(): void
    {
        $pages = [
            'register' => ['회원가입', '[pkb_register]'],
            'login' => ['로그인', '[pkb_login]'],
            'account' => ['마이페이지', '[pkb_account]'],
            'account-2fa' => ['2단계 인증 설정', '[pkb_account_2fa]'],
            'lost-password' => ['비밀번호 재설정', '[pkb_lost_password]'],
            'reset-password' => ['새 비밀번호 설정', '[pkb_reset_password]'],
            'terms-of-service' => ['서비스 이용약관', self::terms_content()],
            'privacy-policy' => ['개인정보처리방침', self::privacy_content()],
        ];

        foreach ($pages as $slug => [$title, $content]) {
            $page = get_page_by_path($slug);
            if ($page) {
                if (in_array($slug, ['terms-of-service', 'privacy-policy'], true)) {
                    $stored_version = get_post_meta($page->ID, '_pkb_legal_version', true);
                    if ($stored_version !== self::LEGAL_VERSION) {
                        wp_update_post([
                            'ID' => $page->ID,
                            'post_status' => 'publish',
                            'post_name' => $slug,
                            'post_title' => $title,
                            'post_content' => $content,
                        ]);
                        update_post_meta($page->ID, '_pkb_legal_version', self::LEGAL_VERSION);
                    }
                }
                continue;
            }

            $page_id = wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_name' => $slug,
                'post_title' => $title,
                'post_content' => $content,
            ]);
            if (!is_wp_error($page_id) && in_array($slug, ['terms-of-service', 'privacy-policy'], true)) {
                update_post_meta((int) $page_id, '_pkb_legal_version', self::LEGAL_VERSION);
            }
        }
    }

    public function ensure_pages(): void
    {
        self::create_pages();
    }

    private static function terms_content(): string
    {
        return <<<'HTML'
<div class="pkb-legal-page">
<p class="pkb-legal-meta">시행일: 2026.06.10</p>

<h2>1. 목적</h2>
<p>이 약관은 Mobigist(이하 "서비스")가 운영하는 WordPress 기반 개인 지식 블로그의 이용 조건, 회원의 권리와 의무, 서비스 운영 기준 및 기타 필요한 사항을 정하기 위한 것입니다.</p>

<h2>2. 용어의 정의</h2>
<ol>
<li>"서비스"란 Mobigist가 제공하는 웹사이트, 게시글, 회원 기능, 댓글, 좋아요, 그래프 뷰, 내부 링크 및 이에 부수되는 기능을 의미합니다.</li>
<li>"회원"이란 서비스가 정한 절차에 따라 가입하고 이메일 인증을 완료하여 회원 전용 기능을 이용할 수 있는 자를 말합니다.</li>
<li>"콘텐츠"란 서비스에 게시된 게시글, 이미지, 텍스트, 그래프, 댓글, 데이터 등 일체의 정보를 말합니다.</li>
<li>"좋아요"란 회원이 게시글 또는 댓글에 대해 하트 형태로 반응을 표시하는 기능을 말합니다.</li>
</ol>

<h2>3. 약관의 게시 및 변경</h2>
<ol>
<li>서비스는 이 약관의 내용을 이용자가 확인할 수 있도록 웹사이트에 게시합니다.</li>
<li>서비스는 관련 법령을 위반하지 않는 범위에서 이 약관을 변경할 수 있습니다.</li>
<li>약관을 변경하는 경우 적용일자 및 주요 변경 내용을 웹사이트를 통해 공지합니다.</li>
</ol>

<h2>4. 서비스의 제공</h2>
<ol>
<li>서비스는 이동에 관련된 다양한 기록, 게시글, 그래프 뷰, 내부 링크, 댓글, 대댓글, 좋아요, 회원 계정 및 마이페이지 기능을 제공합니다.</li>
<li>일부 게시글은 게시글 메타데이터로 지정된 권한에 따라 특별회원, 운영자 또는 관리자에게만 제공될 수 있습니다.</li>
<li>서비스는 운영상 또는 기술상 필요에 따라 제공 내용을 변경할 수 있으며, 시스템 점검, 장애 대응, 서비스 개선 등의 사유가 있는 경우 서비스의 전부 또는 일부를 일시적으로 중단할 수 있습니다.</li>
</ol>

<h2>5. 회원가입 및 계정 관리</h2>
<ol>
<li>회원가입은 아이디, 이메일, 비밀번호를 입력하고 이용약관 및 개인정보처리방침에 동의한 뒤 이메일 인증을 완료하는 방식으로 이루어집니다.</li>
<li>이메일 인증을 완료하지 않은 계정은 로그인 및 서비스 이용이 제한될 수 있으며, 미인증 계정은 운영 정책에 따라 정리될 수 있습니다.</li>
<li>서비스는 타인의 정보 도용, 허위 정보 기재, 법령 또는 공서양속에 반하는 목적, 서비스 운영 방해 우려가 있는 경우 회원가입 신청을 거절하거나 이용을 제한할 수 있습니다.</li>
<li>회원은 자신의 계정정보를 스스로 관리하여야 하며, 이를 제3자에게 양도, 대여 또는 공유해서는 안 됩니다.</li>
<li>관리자와 운영자는 계정 보호를 위해 2단계 인증이 요구될 수 있고, 일반회원과 특별회원도 선택적으로 2단계 인증을 설정할 수 있습니다.</li>
</ol>

<h2>6. 댓글, 대댓글 및 좋아요 이용</h2>
<ol>
<li>댓글, 대댓글 및 좋아요 기능은 로그인한 회원에게만 제공됩니다. 비회원 댓글은 허용되지 않습니다.</li>
<li>로그인한 회원의 댓글은 별도 운영상 제한이 없는 한 즉시 승인될 수 있습니다.</li>
<li>회원은 자신이 작성한 댓글을 서비스가 제공하는 범위에서 수정하거나 삭제할 수 있습니다.</li>
<li>대댓글은 댓글에 대한 1단계 답글로 제한될 수 있습니다.</li>
<li>댓글은 서비스 설정에 따라 페이지 단위로 나뉘어 표시될 수 있습니다.</li>
<li>회원은 비방, 욕설, 혐오표현, 명예훼손, 허위사실, 광고, 도배, 권리 침해, 법령 위반 또는 서비스 운영을 방해하는 내용을 게시해서는 안 됩니다.</li>
<li>서비스는 부적절한 댓글이나 행위에 대하여 사전 통지 없이 숨김, 삭제, 승인 보류, 이용 제한 등의 조치를 할 수 있습니다.</li>
<li>좋아요 기능의 표시 방식 및 집계 방식은 운영 정책에 따라 변경될 수 있습니다.</li>
</ol>

<h2>7. 이용자의 의무</h2>
<ol>
<li>이용자는 관련 법령, 이 약관, 개인정보처리방침 및 서비스 안내를 준수하여야 합니다.</li>
<li>이용자는 타인의 개인정보 또는 계정을 도용하거나, 서비스의 보안 체계를 침해하거나, 자동화 수단으로 비정상적인 요청을 보내서는 안 됩니다.</li>
<li>이용자는 서비스 또는 제3자의 저작권, 상표권, 초상권, 개인정보 등 권리를 침해해서는 안 됩니다.</li>
</ol>

<h2>8. 콘텐츠에 대한 권리</h2>
<ol>
<li>서비스가 작성하거나 편집하여 제공하는 게시글, 이미지, 코드, 디자인, 그래프 표현 및 기타 콘텐츠의 권리는 서비스 또는 정당한 권리자에게 귀속됩니다.</li>
<li>이용자는 서비스가 명시적으로 허용하지 않는 한 서비스의 콘텐츠를 복제, 전송, 배포, 전시, 출판, 방송, 2차적 저작물 작성 기타 방식으로 이용할 수 없습니다.</li>
<li>회원이 작성한 댓글 등 게시물의 권리는 원칙적으로 작성자에게 귀속됩니다. 다만 회원은 서비스 운영, 표시, 보관, 보안 점검 및 분쟁 대응에 필요한 범위에서 해당 게시물을 사용할 수 있는 비독점적 사용권을 서비스에 부여합니다.</li>
<li>회원은 자신이 업로드하거나 작성한 내용에 필요한 권리를 보유하고 있으며, 타인의 권리를 침해하지 않음을 보장하여야 합니다.</li>
</ol>

<h2>9. 서비스 운영 및 책임</h2>
<ol>
<li>서비스는 안정적인 운영을 위해 시스템 점검, 보안 업데이트, 장애 대응 또는 기능 개선을 수행할 수 있으며, 이 과정에서 서비스 이용이 일시적으로 제한될 수 있습니다.</li>
<li>서비스는 이용자가 작성한 댓글, 프로필 이미지 등 회원 생성 콘텐츠의 정확성, 완전성 또는 적법성을 개별적으로 보증하지 않습니다.</li>
<li>서비스는 고의 또는 중대한 과실이 없는 한 이용자 간 또는 이용자와 제3자 간 분쟁에 대하여 책임을 부담하지 않습니다.</li>
</ol>

<h2>10. 이용 제한 및 회원 탈퇴</h2>
<ol>
<li>이용자가 약관 또는 법령을 위반하거나 서비스 운영을 방해하는 경우 서비스는 이용 제한, 댓글 삭제, 계정 제한 등의 조치를 할 수 있습니다.</li>
<li>회원은 마이페이지에서 회원 탈퇴를 요청할 수 있습니다.</li>
<li>회원 탈퇴 시 계정, 작성 댓글, 좋아요 기록, 프로필 이미지는 즉시 삭제됩니다. 다만 법령상 보존 의무가 있거나 보안 및 분쟁 대응에 필요한 최소 기록은 관계 법령과 개인정보처리방침에 따라 필요한 기간 동안 보관될 수 있습니다.</li>
</ol>

<h2>11. 개인정보 보호</h2>
<p>서비스는 개인정보를 개인정보처리방침에 따라 처리합니다. 개인정보의 수집, 이용, 보관, 파기, 권리 행사에 관한 구체적인 내용은 개인정보처리방침을 따릅니다.</p>

<h2>12. 통지</h2>
<p>서비스는 필요한 경우 웹사이트 게시, 이메일 또는 기타 합리적인 방법으로 회원에게 통지할 수 있습니다. 전체 이용자에게 적용되는 사항은 웹사이트 게시로 통지할 수 있습니다.</p>

<h2>13. 준거법 및 관할</h2>
<p>이 약관은 대한민국 법률에 따라 해석됩니다. 서비스와 이용자 사이의 분쟁은 관련 법령에서 정한 절차와 관할에 따릅니다.</p>
</div>
HTML;
    }

    private static function privacy_content(): string
    {
        $content = <<<'HTML'
<div class="pkb-legal-page">
<p class="pkb-legal-meta">시행일: 2026.06.10</p>

<h2>1. 개인정보의 처리 목적</h2>
<p>Mobigist(이하 "서비스")는 WordPress 기반 개인 지식 블로그의 회원가입, 이메일 인증, 로그인, 댓글 및 좋아요, 프로필 관리, 2단계 인증, 보안 점검, 문의 대응, 이용 통계 확인 및 서비스 개선을 위하여 개인정보를 처리합니다.</p>

<h2>2. 처리하는 개인정보 항목</h2>
<ol>
<li>회원가입 및 계정 관리: 아이디, 이메일 주소, 비밀번호 해시, 가입일, 이메일 인증 여부, 약관 동의 시각 및 약관 버전</li>
<li>이메일 인증 및 계정 복구: 이메일 인증 토큰 해시와 만료시각, 비밀번호 재설정 토큰, 이메일 변경 확인 토큰, 메일 발송 기록</li>
<li>로그인 및 보안: 로그인 성공·실패 기록, 로그인 시도 제한 기록, IP 주소, 브라우저 및 기기 정보, 세션 및 쿠키 정보</li>
<li>2단계 인증: 2단계 인증 사용 여부, 인증 방식, 인증 앱 설정 정보, 복구 코드 해시, 2단계 인증 성공 기록</li>
<li>서비스 이용: 댓글, 대댓글, 게시글 좋아요, 댓글 좋아요, 게시글 접근 권한 확인, 서비스 이용 기록</li>
<li>프로필 관리: 표시 이름, 이름, 웹사이트, 소개글, 프로필 이미지 파일, 프로필 이미지 URL 및 이미지 파일 메타데이터</li>
<li>자동 생성 정보: 접속 로그, 쿠키, 세션 정보, 브라우저 종류 및 버전, 운영체제 정보, 기기 정보</li>
</ol>

<h2>3. 개인정보의 보유 및 이용 기간</h2>
<ol>
<li>회원 정보는 회원 탈퇴 시 지체 없이 삭제하는 것을 원칙으로 합니다.</li>
<li>프로필 이미지는 회원이 삭제하거나 탈퇴하는 경우 삭제됩니다.</li>
<li>댓글, 대댓글, 좋아요 기록은 회원 탈퇴 또는 삭제 요청 시 삭제됩니다. 다만 분쟁 대응 및 법령 준수를 위해 필요한 최소 정보는 일정 기간 보관될 수 있습니다.</li>
<li>미인증 계정은 운영 정책에 따라 일정 기간 후 삭제될 수 있습니다.</li>
<li>보안 로그와 감사 기록은 부정 이용 방지, 보안 사고 대응 및 분쟁 해결을 위해 필요한 기간 동안 보관될 수 있습니다.</li>
<li>관계 법령에서 보존을 요구하는 정보가 있는 경우 해당 법령이 정한 기간 동안 보관할 수 있습니다.</li>
</ol>

<h2>4. 개인정보의 제3자 제공</h2>
<p>서비스는 이용자의 개인정보를 원칙적으로 제3자에게 제공하지 않습니다. 다만 이용자의 동의가 있거나 법령에 근거한 요청이 있는 경우에는 예외로 합니다. 현재 일반적인 회원 정보, 댓글 정보 및 좋아요 정보는 제3자에게 판매하거나 광고·마케팅 목적으로 제공하지 않습니다.</p>

<h2>5. 개인정보 처리의 위탁 및 외부 서비스</h2>
<p>서비스 운영 과정에서 다음과 같은 외부 서비스, 소프트웨어 또는 인프라가 사용될 수 있습니다.</p>
<ol>
<li>WordPress 및 관련 플러그인: 회원, 댓글, 보안, 2단계 인증 및 콘텐츠 관리</li>
<li>메일 발송 시스템: 회원가입 인증, 비밀번호 재설정, 이메일 변경 확인 메일 발송</li>
<li>서버 및 데이터베이스 인프라: 서비스 운영, 데이터 저장 및 백업</li>
<li>Amazon Web Services: 클라우드 인프라 제공 및 서버 운영 환경 지원</li>
<li>Google Analytics: 서비스 이용 행태 분석 및 통계 확인</li>
</ol>
<p>외부 서비스 이용 내용은 운영 방식의 변경에 따라 조정될 수 있으며, 중요한 변경이 있는 경우 본 방침을 통해 안내합니다.</p>

<h2>6. 개인정보의 국외 이전</h2>
<p>서비스 운영 과정에서 외부 인프라 또는 분석 도구를 이용함에 따라 개인정보 또는 접속정보가 국외 서버를 경유하거나 저장될 수 있습니다.</p>
<ol>
<li>이전받는 자: Amazon Web Services, Google LLC</li>
<li>이전 국가: 미국 등 각 서비스 제공자의 인프라 운영 국가</li>
<li>이전 항목: 이메일 주소, 회원 식별 정보, 접속 로그, IP 주소, 쿠키 및 세션 정보, 브라우저 및 기기 정보, 서비스 이용 기록</li>
<li>이전 시기 및 방법: 서비스 이용 시 네트워크를 통한 전송</li>
<li>이전 목적: 클라우드 인프라 운영, 서비스 이용 통계 분석 및 서비스 안정성 개선</li>
<li>보유 및 이용 기간: 각 처리 목적 달성 시까지 또는 관계 법령 및 각 서비스 제공자의 정책에 따름</li>
</ol>
<p>서비스는 폰트를 로컬 호스팅하도록 설계되어 있으며, 웹폰트 제공을 목적으로 한 별도 국외 이전은 전제로 하지 않습니다.</p>

<h2>7. 개인정보의 파기 절차 및 방법</h2>
<ol>
<li>처리 목적 달성, 보유기간 경과 또는 회원 탈퇴 등으로 개인정보가 불필요해진 경우 지체 없이 파기합니다.</li>
<li>전자적 파일은 복구 또는 재생이 어렵도록 삭제하고, 종이 문서는 분쇄 또는 소각합니다.</li>
<li>법령상 보관이 필요한 정보는 별도 분리하여 보관하고 해당 목적 외로 사용하지 않습니다.</li>
</ol>

<h2>8. 정보주체의 권리와 행사 방법</h2>
<p>이용자는 마이페이지 또는 문의 창구를 통해 개인정보 열람, 정정, 삭제, 처리정지, 동의 철회, 회원 탈퇴를 요청할 수 있습니다. 서비스는 관련 법령에 따라 지체 없이 필요한 조치를 합니다. 다만 법령에서 정한 사유가 있는 경우 일부 권리 행사가 제한될 수 있습니다.</p>

<h2>9. 개인정보의 안전성 확보조치</h2>
<ol>
<li>비밀번호 해시 저장 및 인증 토큰 관리</li>
<li>이메일 인증 및 선택적 또는 필수 2단계 인증 제공</li>
<li>관리자 페이지 접근 제한 및 회원용 마이페이지 제공</li>
<li>프로필 이미지 업로드 형식, 용량, 크기 제한 및 과도한 요청 제한</li>
<li>로그인 시도 제한, 보안 로그 관리, nonce 검증 및 권한 최소화</li>
<li>보안 업데이트 및 접근 권한 점검</li>
</ol>

<h2>10. 쿠키와 세션</h2>
<p>서비스는 로그인 상태 유지, 보안 검증, 댓글 작성, 2단계 인증, 알림 메시지 표시를 위해 쿠키와 세션 정보를 사용할 수 있습니다. 이용자는 브라우저 설정으로 쿠키 저장을 거부할 수 있으나, 이 경우 로그인 등 일부 기능이 제한될 수 있습니다.</p>

<h2>11. 댓글, 좋아요 및 프로필 정보의 처리</h2>
<ol>
<li>댓글과 대댓글은 게시글 화면에 공개될 수 있으며, 작성자 표시 정보와 작성 시간이 함께 표시될 수 있습니다.</li>
<li>좋아요 정보는 게시글 또는 댓글별 집계 수로 표시될 수 있습니다.</li>
<li>프로필 이미지는 댓글, 마이페이지, 사용자 표시 영역에서 노출될 수 있습니다.</li>
<li>회원은 자신이 작성한 댓글을 서비스가 제공하는 범위에서 수정 또는 삭제할 수 있습니다.</li>
</ol>

<h2>12. 개인정보 보호책임자 및 문의처</h2>
<ul>
<li>개인정보 보호책임자: {{privacy_officer_name}}</li>
<li>담당 이메일: {{privacy_contact_email}}</li>
<li>담당부서: {{privacy_contact_department}}</li>
</ul>

<h2>13. 권익침해 구제방법</h2>
<ol>
<li>개인정보침해신고센터: 118 / https://privacy.kisa.or.kr</li>
<li>개인정보분쟁조정위원회: 1833-6972 / https://www.kopico.go.kr</li>
<li>대검찰청: 1301 / https://www.spo.go.kr</li>
<li>경찰청: 182 / https://ecrm.police.go.kr</li>
</ol>

<h2>14. 개인정보처리방침의 변경</h2>
<p>서비스는 관련 법령, 서비스 내용 또는 운영 방식 변경에 따라 개인정보처리방침을 수정할 수 있습니다. 중요한 변경 사항은 웹사이트를 통해 안내합니다.</p>
</div>
HTML;

        return strtr($content, [
            '{{privacy_officer_name}}' => esc_html(self::privacy_officer_name()),
            '{{privacy_contact_email}}' => esc_html(self::privacy_contact_email()),
            '{{privacy_contact_department}}' => esc_html(self::privacy_contact_department()),
        ]);
    }

    private static function privacy_officer_name(): string
    {
        $name = trim((string) getenv('PKB_PRIVACY_OFFICER_NAME'));
        return $name !== '' ? $name : '서비스 운영자';
    }

    private static function privacy_contact_email(): string
    {
        $email = sanitize_email((string) getenv('PKB_PRIVACY_CONTACT_EMAIL'));
        if ($email !== '') {
            return $email;
        }

        $admin_email = sanitize_email((string) get_option('admin_email'));
        return $admin_email !== '' ? $admin_email : 'privacy@example.com';
    }

    private static function privacy_contact_department(): string
    {
        $department = trim((string) getenv('PKB_PRIVACY_CONTACT_DEPARTMENT'));
        return $department !== '' ? $department : '서비스 운영자';
    }

    private static function set_default_options(): void
    {
        add_option('pkb_unverified_retention_days', 7);
        add_option('pkb_email_token_ttl_hours', 24);
        update_option('users_can_register', 0);
    }

    private static function schedule_cleanup_static(): void
    {
        if (!wp_next_scheduled('pkb_cleanup_unverified_users')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'pkb_cleanup_unverified_users');
        }
    }

    public function schedule_cleanup(): void
    {
        self::schedule_cleanup_static();
    }

    public function register_shortcodes(): void
    {
        add_shortcode('pkb_register', [$this, 'shortcode_register']);
        add_shortcode('pkb_login', [$this, 'shortcode_login']);
        add_shortcode('pkb_account', [$this, 'shortcode_account']);
        add_shortcode('pkb_account_2fa', [$this, 'shortcode_account_2fa']);
        add_shortcode('pkb_lost_password', [$this, 'shortcode_lost_password']);
        add_shortcode('pkb_reset_password', [$this, 'shortcode_reset_password']);
    }

    public function enqueue_assets(): void
    {
        wp_enqueue_style('pkb-registration', PKB_REGISTRATION_URL . 'assets/css/pkb-registration.css', [], PKB_REGISTRATION_VERSION);

        if (is_page('account-2fa') && is_user_logged_in() && defined('TWO_FACTOR_VERSION')) {
            if (!wp_script_is('two-factor-qr-code-generator', 'registered')) {
                wp_register_script(
                    'two-factor-qr-code-generator',
                    plugins_url('two-factor/includes/qrcode-generator/qrcode.js'),
                    [],
                    TWO_FACTOR_VERSION,
                    true
                );
            }

            wp_enqueue_script(
                'pkb-registration-2fa-qr',
                PKB_REGISTRATION_URL . 'assets/js/account-2fa-qr.js',
                ['two-factor-qr-code-generator'],
                PKB_REGISTRATION_VERSION,
                true
            );
            wp_localize_script('pkb-registration-2fa-qr', 'pkbRegistration2fa', [
                'restUrl' => esc_url_raw(rest_url(Two_Factor_Core::REST_NAMESPACE . '/totp')),
                'backupCodesRestUrl' => esc_url_raw(rest_url(Two_Factor_Core::REST_NAMESPACE . '/generate-backup-codes')),
                'nonce' => wp_create_nonce('wp_rest'),
                'userId' => get_current_user_id(),
                'qrCodeLabel' => 'Authenticator App QR Code',
                'invalidCodeMessage' => '인증 코드를 확인해 주세요.',
            ]);
        }
    }

    public function handle_forms(): void
    {
        if (!isset($_POST['pkb_registration_action'])) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['pkb_registration_action']));
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'pkb_registration_' . $action)) {
            wp_die('Invalid request.');
        }

        if ($action === 'register') {
            $this->handle_register();
        } elseif ($action === 'login') {
            $this->handle_login();
        } elseif ($action === 'resend_verification') {
            $this->handle_resend_verification();
        } elseif ($action === 'lost_password') {
            $this->handle_lost_password();
        } elseif ($action === 'reset_password') {
            $this->handle_reset_password();
        } elseif ($action === 'update_2fa') {
            $this->handle_update_two_factor();
        } elseif ($action === 'update_profile') {
            $this->handle_update_profile();
        } elseif ($action === 'change_password') {
            $this->handle_change_password();
        } elseif ($action === 'upload_avatar') {
            $this->handle_upload_avatar();
        } elseif ($action === 'delete_avatar') {
            $this->handle_delete_avatar();
        } elseif ($action === 'destroy_other_sessions') {
            $this->handle_destroy_other_sessions();
        } elseif ($action === 'delete_account') {
            $this->handle_delete_account();
        }
    }

    private function handle_register(): void
    {
        $this->guard_honeypot(wp_get_referer() ?: home_url('/register/'));

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $username = sanitize_user(wp_unslash($_POST['username'] ?? ''));
        $password = (string) wp_unslash($_POST['password'] ?? '');
        $terms_accepted = !empty($_POST['terms_accepted']);
        $privacy_accepted = !empty($_POST['privacy_accepted']);

        if (!$email || !$username || strlen($password) < 10) {
            $this->redirect_with_message(wp_get_referer() ?: home_url('/register/'), '이메일, 아이디, 10자 이상 비밀번호를 입력해 주세요.', 'error');
        }

        if (!$terms_accepted || !$privacy_accepted) {
            $this->redirect_with_message(wp_get_referer() ?: home_url('/register/'), '이용약관과 개인정보처리방침에 동의해 주세요.', 'error');
        }

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            $this->redirect_with_message(wp_get_referer() ?: home_url('/register/'), '회원가입 정보를 확인해 주세요. 이미 사용 중인 아이디 또는 이메일일 수 있습니다.', 'error');
        }

        $user = new WP_User($user_id);
        $user->set_role('pkb_member');
        update_user_meta($user_id, 'pkb_email_verified', '0');
        update_user_meta($user_id, 'pkb_registered_at', current_time('mysql'));
        update_user_meta($user_id, 'pkb_terms_accepted_at', current_time('mysql'));
        update_user_meta($user_id, 'pkb_privacy_accepted_at', current_time('mysql'));
        update_user_meta($user_id, 'pkb_terms_version', self::LEGAL_VERSION);
        update_user_meta($user_id, 'pkb_privacy_version', self::LEGAL_VERSION);
        $this->issue_verification_token($user_id);

        $this->redirect_with_message(home_url('/login/'), '인증 메일을 보냈습니다. 이메일 인증 후 로그인할 수 있습니다.');
    }

    private function handle_login(): void
    {
        $this->guard_honeypot(wp_get_referer() ?: home_url('/login/'));

        $login = sanitize_text_field(wp_unslash($_POST['log'] ?? ''));

        $creds = [
            'user_login' => $login,
            'user_password' => (string) wp_unslash($_POST['pwd'] ?? ''),
            'remember' => !empty($_POST['rememberme']),
        ];

        $user = wp_signon($creds, is_ssl());
        if (is_wp_error($user)) {
            $this->record_login_audit(0, $login, false, $user->get_error_code());
            $message = $this->is_login_lockout_error($user)
                ? '로그인 시도가 너무 많습니다. 잠시 후 다시 시도해 주세요.'
                : '아이디 또는 비밀번호를 확인해 주세요.';
            $this->redirect_with_message(wp_get_referer() ?: home_url('/login/'), $message, 'error');
        }

        $this->record_login_audit((int) $user->ID, $login, true, 'password_ok');

        $redirect_to = esc_url_raw(wp_unslash($_POST['redirect_to'] ?? ''));
        $redirect_url = $redirect_to !== '' ? wp_validate_redirect($redirect_to, home_url('/')) : home_url('/');
        wp_safe_redirect($redirect_url);
        exit;
    }

    private function handle_resend_verification(): void
    {
        $this->guard_honeypot(wp_get_referer() ?: home_url('/login/'));

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $user = $email ? get_user_by('email', $email) : false;
        if ($user && get_user_meta($user->ID, 'pkb_email_verified', true) !== '1') {
            $this->issue_verification_token((int) $user->ID);
        }

        $this->redirect_with_message(wp_get_referer() ?: home_url('/login/'), '계정이 존재하고 아직 인증되지 않았다면 인증 메일을 다시 보냈습니다.');
    }

    private function handle_lost_password(): void
    {
        $this->guard_honeypot(wp_get_referer() ?: home_url('/lost-password/'));

        $login = sanitize_text_field(wp_unslash($_POST['user_login'] ?? ''));
        if (!is_email($login)) {
            $this->redirect_with_message(home_url('/lost-password/'), '입력하신 이메일 양식이 적합하지 않습니다. 확인해주세요', 'error');
        }

        $user = get_user_by('email', sanitize_email($login));

        if ($user instanceof WP_User) {
            $key = get_password_reset_key($user);
            if (!is_wp_error($key)) {
                $url = add_query_arg([
                    'key' => rawurlencode($key),
                    'login' => rawurlencode($user->user_login),
                ], home_url('/reset-password/'));

                wp_mail(
                    $user->user_email,
                    '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] 비밀번호 재설정',
                    "아래 링크를 열어 새 비밀번호를 설정해 주세요.\n\n" . $url
                );
            }
        }

        $this->redirect_with_message(home_url('/lost-password/'), '입력하신 이메일로 비밀번호 재설정 메일을 보내드렸습니다. 확인해주세요');
    }

    private function handle_reset_password(): void
    {
        $login = sanitize_text_field(wp_unslash($_POST['login'] ?? ''));
        $key = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));
        $password = (string) wp_unslash($_POST['password'] ?? '');
        $confirm = (string) wp_unslash($_POST['password_confirm'] ?? '');

        if (strlen($password) < 10 || $password !== $confirm) {
            $this->redirect_with_message(wp_get_referer() ?: home_url('/reset-password/'), '새 비밀번호는 10자 이상이고 확인값과 같아야 합니다.', 'error');
        }

        $user = check_password_reset_key($key, $login);
        if (is_wp_error($user)) {
            $this->redirect_with_message(home_url('/lost-password/'), '비밀번호 재설정 링크가 유효하지 않거나 만료되었습니다.', 'error');
        }

        reset_password($user, $password);
        $this->record_login_audit((int) $user->ID, $user->user_login, true, 'password_reset');
        $this->redirect_with_message(home_url('/login/'), '비밀번호를 변경했습니다. 새 비밀번호로 로그인해 주세요.');
    }

    private function handle_update_two_factor(): void
    {
        $user = $this->require_current_user();
        if (!class_exists('Two_Factor_Core')) {
            $this->redirect_with_message(home_url('/account-2fa/'), '2단계 인증 설정을 변경할 수 없습니다.', 'error');
        }

        Two_Factor_Core::user_two_factor_options_update($user->ID);
        $this->ensure_staff_two_factor_for_user($user);
        $this->redirect_with_message(home_url('/account-2fa/'), '2단계 인증 설정을 저장했습니다.');
    }

    private function handle_update_profile(): void
    {
        $user = $this->require_current_user();
        $nickname = sanitize_text_field(wp_unslash($_POST['nickname'] ?? ''));
        if ($nickname === '') {
            $nickname = $user->user_login;
        }

        $update = [
            'ID' => $user->ID,
            'first_name' => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
            'last_name' => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
            'nickname' => $nickname,
            'display_name' => sanitize_text_field(wp_unslash($_POST['display_name'] ?? $nickname)),
            'user_url' => esc_url_raw(wp_unslash($_POST['user_url'] ?? '')),
            'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
        ];

        $result = wp_update_user($update);
        if (is_wp_error($result)) {
            $this->redirect_with_message(home_url('/account/'), '프로필을 저장하지 못했습니다. 입력한 정보를 확인해 주세요.', 'error');
        }

        $new_email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if ($new_email && strcasecmp($new_email, $user->user_email) !== 0) {
            $this->request_email_change($user->ID, $new_email);
            $this->redirect_with_message(home_url('/account/'), '프로필을 저장했고, 새 이메일 주소로 확인 메일을 보냈습니다.');
        }

        $this->redirect_with_message(home_url('/account/'), '프로필을 저장했습니다.');
    }

    private function handle_change_password(): void
    {
        $user = $this->require_current_user();
        $current = (string) wp_unslash($_POST['current_password'] ?? '');
        $new = (string) wp_unslash($_POST['new_password'] ?? '');
        $confirm = (string) wp_unslash($_POST['confirm_password'] ?? '');

        if (!wp_check_password($current, $user->user_pass, $user->ID)) {
            $this->redirect_with_message(home_url('/account/'), '현재 비밀번호가 맞지 않습니다.', 'error');
        }

        if (strlen($new) < 10 || $new !== $confirm) {
            $this->redirect_with_message(home_url('/account/'), '새 비밀번호는 10자 이상이고 확인값과 같아야 합니다.', 'error');
        }

        wp_set_password($new, $user->ID);
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true, is_ssl());

        $this->redirect_with_message(home_url('/account/'), '비밀번호를 변경했습니다.');
    }

    private function handle_upload_avatar(): void
    {
        $user = $this->require_current_user();
        if (!$this->consume_avatar_upload_quota($user->ID)) {
            $this->redirect_with_message(home_url('/account/'), '프로필 사진 변경 요청이 너무 잦습니다. 잠시 후 다시 시도해 주세요.', 'error');
        }

        $file = $_FILES['avatar'] ?? null;
        if (!is_array($file) || empty($file['tmp_name'])) {
            $this->redirect_with_message(home_url('/account/'), '업로드할 이미지를 선택해 주세요.', 'error');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $this->redirect_with_message(home_url('/account/'), '이미지를 업로드하지 못했습니다.', 'error');
        }

        if ((int) ($file['size'] ?? 0) > self::AVATAR_MAX_BYTES) {
            $this->redirect_with_message(home_url('/account/'), '프로필 사진은 2MB 이하만 업로드할 수 있습니다.', 'error');
        }

        $allowed = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];
        $checked = wp_check_filetype_and_ext((string) $file['tmp_name'], sanitize_file_name((string) ($file['name'] ?? 'avatar')), $allowed);
        if (empty($checked['type']) || !in_array($checked['type'], $allowed, true)) {
            $this->redirect_with_message(home_url('/account/'), 'JPG, PNG, WebP 이미지만 업로드할 수 있습니다.', 'error');
        }

        $saved = $this->save_resized_avatar($user->ID, (string) $file['tmp_name'], (string) $checked['type']);
        if (is_wp_error($saved)) {
            $this->redirect_with_message(home_url('/account/'), '프로필 사진을 처리하지 못했습니다. 다른 이미지를 선택해 주세요.', 'error');
        }

        $this->redirect_with_message(home_url('/account/'), '프로필 사진을 변경했습니다.');
    }

    private function handle_delete_avatar(): void
    {
        $user = $this->require_current_user();
        $this->delete_user_avatar($user->ID);

        $this->redirect_with_message(home_url('/account/'), '프로필 사진을 삭제했습니다.');
    }

    private function handle_destroy_other_sessions(): void
    {
        $this->require_current_user();
        wp_destroy_other_sessions();

        $this->redirect_with_message(home_url('/account/'), '현재 브라우저를 제외한 다른 로그인 세션을 로그아웃했습니다.');
    }

    private function handle_delete_account(): void
    {
        $user = $this->require_current_user();
        do_action('pkb_before_user_account_delete', $user->ID);
        wp_logout();

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user->ID);

        wp_safe_redirect(home_url('/'));
        exit;
    }

    private function issue_verification_token(int $user_id): void
    {
        $token = wp_generate_password(32, false, false);
        $ttl = max(1, (int) get_option('pkb_email_token_ttl_hours', 24));

        update_user_meta($user_id, 'pkb_email_token_hash', wp_hash_password($token));
        update_user_meta($user_id, 'pkb_email_token_expires', time() + ($ttl * HOUR_IN_SECONDS));

        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $url = add_query_arg([
            'pkb_verify' => $user_id,
            'token' => rawurlencode($token),
        ], home_url('/login/'));

        wp_mail(
            $user->user_email,
            '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] 이메일 인증',
            "아래 링크를 열어 이메일 인증을 완료해 주세요.\n\n" . $url
        );
    }

    public function maybe_verify_email(): void
    {
        if (!isset($_GET['pkb_verify'], $_GET['token'])) {
            return;
        }

        $user_id = absint($_GET['pkb_verify']);
        $token = sanitize_text_field(wp_unslash($_GET['token']));
        $hash = (string) get_user_meta($user_id, 'pkb_email_token_hash', true);
        $expires = (int) get_user_meta($user_id, 'pkb_email_token_expires', true);

        if (!$hash || $expires < time() || !wp_check_password($token, $hash)) {
            $this->redirect_with_message(home_url('/login/'), '인증 링크가 유효하지 않거나 만료되었습니다.', 'error');
        }

        update_user_meta($user_id, 'pkb_email_verified', '1');
        delete_user_meta($user_id, 'pkb_email_token_hash');
        delete_user_meta($user_id, 'pkb_email_token_expires');

        $this->redirect_with_message(home_url('/login/'), '이메일 인증이 완료되었습니다. 이제 로그인할 수 있습니다.');
    }

    private function request_email_change(int $user_id, string $new_email): void
    {
        if (!is_email($new_email)) {
            $this->redirect_with_message(home_url('/account/'), '유효한 이메일 주소를 입력해 주세요.', 'error');
        }

        $owner_id = email_exists($new_email);
        if ($owner_id && (int) $owner_id !== $user_id) {
            $this->redirect_with_message(home_url('/account/'), '이미 사용 중인 이메일 주소입니다.', 'error');
        }

        $token = wp_generate_password(32, false, false);
        $ttl = max(1, (int) get_option('pkb_email_token_ttl_hours', 24));

        update_user_meta($user_id, 'pkb_pending_email', $new_email);
        update_user_meta($user_id, 'pkb_email_change_token_hash', wp_hash_password($token));
        update_user_meta($user_id, 'pkb_email_change_expires', time() + ($ttl * HOUR_IN_SECONDS));

        $url = add_query_arg([
            'pkb_confirm_email' => $user_id,
            'token' => rawurlencode($token),
        ], home_url('/account/'));

        wp_mail(
            $new_email,
            '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] 이메일 변경 확인',
            "아래 링크를 열어 이메일 변경을 완료해 주세요.\n\n" . $url
        );
    }

    public function maybe_confirm_email_change(): void
    {
        if (!isset($_GET['pkb_confirm_email'], $_GET['token'])) {
            return;
        }

        $user_id = absint($_GET['pkb_confirm_email']);
        $token = sanitize_text_field(wp_unslash($_GET['token']));
        $hash = (string) get_user_meta($user_id, 'pkb_email_change_token_hash', true);
        $expires = (int) get_user_meta($user_id, 'pkb_email_change_expires', true);
        $pending_email = sanitize_email((string) get_user_meta($user_id, 'pkb_pending_email', true));

        if (!$pending_email || !$hash || $expires < time() || !wp_check_password($token, $hash)) {
            $this->redirect_with_message(home_url('/account/'), '이메일 변경 링크가 유효하지 않거나 만료되었습니다.', 'error');
        }

        $owner_id = email_exists($pending_email);
        if ($owner_id && (int) $owner_id !== $user_id) {
            $this->clear_pending_email_change($user_id);
            $this->redirect_with_message(home_url('/account/'), '이미 사용 중인 이메일 주소입니다.', 'error');
        }

        $result = wp_update_user([
            'ID' => $user_id,
            'user_email' => $pending_email,
        ]);

        $this->clear_pending_email_change($user_id);
        if (is_wp_error($result)) {
            $this->redirect_with_message(home_url('/account/'), '이메일 주소를 변경하지 못했습니다. 잠시 후 다시 시도해 주세요.', 'error');
        }

        $this->redirect_with_message(home_url('/account/'), '이메일 주소를 변경했습니다.');
    }

    private function clear_pending_email_change(int $user_id): void
    {
        delete_user_meta($user_id, 'pkb_pending_email');
        delete_user_meta($user_id, 'pkb_email_change_token_hash');
        delete_user_meta($user_id, 'pkb_email_change_expires');
    }

    public function block_unverified_login($user, string $username, string $password)
    {
        if ($user instanceof WP_User && get_user_meta($user->ID, 'pkb_email_verified', true) !== '1' && !user_can($user, 'manage_options')) {
            return new WP_Error('pkb_unverified', '이메일 인증 후 로그인할 수 있습니다.');
        }

        return $user;
    }

    public function cleanup_unverified_users(): void
    {
        $days = max(1, (int) get_option('pkb_unverified_retention_days', 7));
        $before = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $users = get_users([
            'meta_query' => [
                [
                    'key' => 'pkb_email_verified',
                    'value' => '0',
                ],
                [
                    'key' => 'pkb_registered_at',
                    'value' => $before,
                    'compare' => '<=',
                    'type' => 'DATETIME',
                ],
            ],
            'fields' => 'ID',
        ]);

        if (!$users) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($users as $user_id) {
            do_action('pkb_before_user_account_delete', (int) $user_id);
            wp_delete_user((int) $user_id);
        }
    }

    public function redirect_non_staff_from_admin(): void
    {
        if (!is_user_logged_in() || wp_doing_ajax() || $this->is_staff_user()) {
            return;
        }

        wp_safe_redirect(home_url('/account/'));
        exit;
    }

    public function redirect_profile_from_admin(): void
    {
        if (!is_user_logged_in() || wp_doing_ajax() || ($GLOBALS['pagenow'] ?? '') !== 'profile.php') {
            return;
        }

        $account_url = home_url('/account/');
        $message = '프로필은 마이페이지에서 수정해 주세요.';

        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html(get_bloginfo('name')); ?></title>
        </head>
        <body>
            <script>
                window.alert(<?php echo wp_json_encode($message); ?>);
                window.location.replace(<?php echo wp_json_encode($account_url); ?>);
            </script>
            <noscript>
                <p><?php echo esc_html($message); ?></p>
                <p><a href="<?php echo esc_url($account_url); ?>">마이페이지로 이동</a></p>
            </noscript>
        </body>
        </html>
        <?php
        exit;
    }

    public function replace_admin_bar_profile_link(WP_Admin_Bar $admin_bar): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        $account_url = home_url('/account/');
        $profile_nodes = ['edit-profile', 'user-info'];
        foreach ($profile_nodes as $node_id) {
            $node = $admin_bar->get_node($node_id);
            if (!$node) {
                continue;
            }

            $admin_bar->add_node([
                'id' => $node->id,
                'parent' => $node->parent,
                'title' => $node->title,
                'href' => $account_url,
                'group' => $node->group,
                'meta' => (array) $node->meta,
            ]);
        }
    }

    public function hide_admin_bar_for_members(bool $show): bool
    {
        if (!is_admin() && is_user_logged_in() && !$this->is_staff_user()) {
            return false;
        }

        return $show;
    }

    private function is_staff_user(?WP_User $user = null): bool
    {
        $user = $user ?: wp_get_current_user();
        if (!$user || !$user->exists()) {
            return false;
        }

        return user_can($user, 'manage_options')
            || user_can($user, 'pkb_moderate_members')
            || user_can($user, 'edit_posts');
    }

    public function configure_two_factor(): void
    {
        if (!class_exists('Two_Factor_Core')) {
            return;
        }

        $providers = [
            'Two_Factor_Email',
            'Two_Factor_Totp',
            'Two_Factor_Backup_Codes',
        ];

        if (get_option('two_factor_enabled_providers') !== $providers) {
            update_option('two_factor_enabled_providers', $providers);
        }
    }

    public function ensure_staff_two_factor($user, string $username = '', string $password = '')
    {
        if ($user instanceof WP_User) {
            $this->ensure_staff_two_factor_for_user($user);
        }

        return $user;
    }

    private function ensure_staff_two_factor_for_user(WP_User $user): void
    {
        if (!$this->is_staff_user($user) || !class_exists('Two_Factor_Core')) {
            return;
        }

        $enabled = get_user_meta($user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true);
        $enabled = is_array($enabled) ? array_values(array_filter($enabled)) : [];
        if ($enabled === []) {
            $enabled[] = 'Two_Factor_Email';
            update_user_meta($user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, $enabled);
        }

        $primary = (string) get_user_meta($user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, true);
        if ($primary === '' || !in_array($primary, $enabled, true)) {
            update_user_meta($user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, $enabled[0]);
        }
    }

    public function record_two_factor_success(WP_User $user): void
    {
        $this->record_login_audit((int) $user->ID, $user->user_login, true, 'two_factor_ok');
    }

    public function redirect_two_factor_revalidation(string $redirect_to, string $requested_redirect_to, $user): string
    {
        $action = sanitize_key(wp_unslash($_REQUEST['action'] ?? ''));
        if ($action !== 'revalidate_2fa') {
            return $redirect_to;
        }

        $requested = (string) wp_unslash($_REQUEST['redirect_to'] ?? $requested_redirect_to);
        if ($requested === '' || strpos($requested, 'user-edit.php') === false || strpos($requested, 'two-factor-options') === false) {
            return $redirect_to;
        }

        return home_url('/account-2fa/');
    }

    public function ensure_staff_two_factor_after_totp_rest($response, $handler, WP_REST_Request $request)
    {
        if (!class_exists('Two_Factor_Core')) {
            return $response;
        }

        $allowed_routes = [
            '/' . Two_Factor_Core::REST_NAMESPACE . '/totp',
            '/' . Two_Factor_Core::REST_NAMESPACE . '/generate-backup-codes',
        ];
        if (!in_array($request->get_route(), $allowed_routes, true)) {
            return $response;
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $user = wp_get_current_user();
        if ($user instanceof WP_User && $user->exists()) {
            $this->ensure_staff_two_factor_for_user($user);
        }

        return $response;
    }

    public function translate_two_factor_text(string $translation, string $text, string $domain): string
    {
        if ($domain !== 'two-factor') {
            return $translation;
        }

        $translations = [
            'Two-Factor Options' => '2단계 인증 설정',
            'Configure a primary two-factor method along with a backup method, such as Recovery Codes, to avoid being locked out if you lose access to your primary method. Methods marked as recommended are more secure and easier to use.' => '기본 2단계 인증 수단과 백업 수단을 함께 설정하면 기본 수단을 사용할 수 없을 때 계정에 접근하지 못하는 상황을 줄일 수 있습니다. 권장 표시가 있는 수단은 더 안전하고 사용하기 쉽습니다.',
            'Authentication for REST API and XML-RPC must use application passwords (defined above) instead of your regular password.' => 'REST API와 XML-RPC 인증에는 일반 비밀번호 대신 애플리케이션 비밀번호를 사용해야 합니다.',
            'Enable %s' => '%s 사용',
            'This method is more secure and easy to use' => '이 수단은 더 안전하고 사용하기 쉽습니다',
            'Recommended' => '권장',
            'Primary Method' => '기본 인증 수단',
            'Default' => '기본값',
            'Select the primary method to use for two-factor authentication when signing into this site.' => '로그인할 때 사용할 기본 2단계 인증 수단을 선택합니다.',
            'To prevent being locked out of your account, consider enabling a backup method like Recovery Codes in case you lose access to your primary authentication method.' => '기본 인증 수단을 사용할 수 없을 때를 대비해 복구 코드 같은 백업 수단을 켜 두는 것을 권장합니다.',
            'To update your Two-Factor options, you must first revalidate your session.' => '2단계 인증 설정을 변경하려면 먼저 현재 세션을 다시 인증해야 합니다.',
            'Revalidate now' => '다시 인증하기',
            'Authenticator App QR Code' => '인증 앱 QR 코드',
            'Please follow these steps in order to complete setup:' => '설정을 완료하려면 아래 단계를 따라 주세요.',
            'Install an authenticator app on your desktop/laptop and/or phone. Popular examples are Microsoft Authenticator, Google Authenticator and Authy.' => '컴퓨터나 휴대폰에 인증 앱을 설치하세요. Microsoft Authenticator, Google Authenticator, Authy 등을 사용할 수 있습니다.',
            'Scan this QR code using the app you installed:' => '설치한 앱으로 이 QR 코드를 스캔하세요.',
            'Loading…' => '불러오는 중...',
            'If scanning isn\'t possible or doesn\'t work, click on the QR code or use the secret key shown below to add the account to your chosen app:' => '스캔할 수 없거나 작동하지 않으면 QR 코드를 누르거나 아래 비밀 키를 사용해 인증 앱에 계정을 추가하세요.',
            'Enter the code generated by the Authenticator app to complete the setup:' => '설정을 완료하려면 인증 앱에서 생성된 코드를 입력하세요.',
            'Authentication Code:' => '인증 코드:',
            'eg. %s' => '예: %s',
            'Verify' => '확인',
            'If the code is rejected, check that your web server time is accurate: %1$s. Your device and server times must match.' => '코드가 거부되면 웹 서버 시간이 정확한지 확인하세요: %1$s. 기기와 서버 시간이 일치해야 합니다.',
            'An authenticator app is currently configured. You will need to re-scan the QR code on all devices if reset.' => '현재 인증 앱이 설정되어 있습니다. 재설정하면 모든 기기에서 QR 코드를 다시 스캔해야 합니다.',
            'Reset authenticator app' => '인증 앱 재설정',
            'Generate new recovery codes' => '새 복구 코드 생성',
            'This invalidates all currently stored codes.' => '현재 저장된 모든 복구 코드가 무효화됩니다.',
            'Write these down! Once you navigate away from this page, you will not be able to view these codes again.' => '이 코드를 따로 기록해 두세요. 이 페이지를 벗어나면 다시 볼 수 없습니다.',
            'Copy Codes' => '코드 복사',
            'Download Codes' => '코드 다운로드',
            'Authentication codes will be sent to %s.' => '인증 코드는 %s 주소로 전송됩니다.',
            'Unable to disable TOTP provider for this user.' => '이 사용자의 인증 앱을 비활성화하지 못했습니다.',
            'Invalid Two Factor Authentication secret key.' => '2단계 인증 비밀 키가 올바르지 않습니다.',
            'Invalid Two Factor Authentication code.' => '2단계 인증 코드가 올바르지 않습니다.',
            'Unable to save Two Factor Authentication code. Please re-scan the QR code and enter the code provided by your application.' => '2단계 인증 코드를 저장하지 못했습니다. QR 코드를 다시 스캔한 뒤 앱에서 제공한 코드를 입력해 주세요.',
            'Unable to enable TOTP provider for this user.' => '이 사용자의 인증 앱을 활성화하지 못했습니다.',
        ];

        return $translations[$text] ?? $translation;
    }

    public function translate_two_factor_text_with_context(string $translation, string $text, string $context, string $domain): string
    {
        if ($domain !== 'two-factor') {
            return $translation;
        }

        $translations = [
            'Provider Label' => [
                'Authenticator App' => '인증 앱 (Google Authenticator, Microsoft Authenticator, Authy 등)',
                'Recovery Codes' => '복구 코드',
                'Email' => '이메일',
            ],
        ];

        return $translations[$context][$text] ?? $translation;
    }

    private function guard_honeypot(string $redirect_url): void
    {
        $trap = trim((string) wp_unslash($_POST['company'] ?? ''));
        if ($trap !== '') {
            $this->record_login_audit(0, 'honeypot', false, 'bot_trap');
            $this->redirect_with_message($redirect_url, '요청을 처리하지 못했습니다. 다시 시도해 주세요.', 'error');
        }
    }

    private function honeypot_field(): string
    {
        return '<p class="pkb-hp-field" aria-hidden="true"><label>회사 <input type="text" name="company" value="" tabindex="-1" autocomplete="off"></label></p>';
    }

    private function client_ip(): string
    {
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        return $ip !== '' ? $ip : 'unknown';
    }

    private function is_login_lockout_error(WP_Error $error): bool
    {
        return (bool) array_intersect(
            ['too_many_retries', 'username_blacklisted'],
            $error->get_error_codes()
        );
    }

    private function record_login_audit(int $user_id, string $login, bool $success, string $reason): void
    {
        $log = get_option('pkb_login_audit_log', []);
        $log = is_array($log) ? $log : [];
        array_unshift($log, [
            'time' => current_time('mysql'),
            'user_id' => $user_id,
            'login' => sanitize_text_field($login),
            'success' => $success ? 1 : 0,
            'reason' => sanitize_key($reason),
            'ip_hash' => hash('sha256', $this->client_ip() . wp_salt('auth')),
        ]);

        update_option('pkb_login_audit_log', array_slice($log, 0, self::AUDIT_LOG_LIMIT), false);
    }

    private function consume_avatar_upload_quota(int $user_id): bool
    {
        $now = time();
        $timestamps = get_user_meta($user_id, 'pkb_avatar_upload_timestamps', true);
        $timestamps = is_array($timestamps) ? array_map('intval', $timestamps) : [];
        $timestamps = array_values(array_filter($timestamps, fn (int $timestamp): bool => $timestamp > $now - self::AVATAR_RATE_WINDOW));
        $last = $timestamps ? max($timestamps) : 0;

        if ($last && $now - $last < self::AVATAR_RATE_COOLDOWN) {
            update_user_meta($user_id, 'pkb_avatar_rate_limited_at', current_time('mysql'));
            return false;
        }

        if (count($timestamps) >= self::AVATAR_RATE_LIMIT) {
            update_user_meta($user_id, 'pkb_avatar_rate_limited_at', current_time('mysql'));
            return false;
        }

        $timestamps[] = $now;
        update_user_meta($user_id, 'pkb_avatar_upload_timestamps', $timestamps);

        return true;
    }

    private function save_resized_avatar(int $user_id, string $source_path, string $mime): true|WP_Error
    {
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return new WP_Error('pkb_avatar_upload_dir', '업로드 디렉터리를 사용할 수 없습니다.');
        }

        $avatar_dir = trailingslashit($upload_dir['basedir']) . 'pkb-avatars';
        if (!wp_mkdir_p($avatar_dir)) {
            return new WP_Error('pkb_avatar_mkdir', '프로필 사진 저장 디렉터리를 만들 수 없습니다.');
        }

        $extension = $this->avatar_extension_for_mime($mime);
        if (!$extension) {
            return new WP_Error('pkb_avatar_type', '지원하지 않는 이미지 형식입니다.');
        }

        $editor = wp_get_image_editor($source_path);
        if (is_wp_error($editor)) {
            return new WP_Error('pkb_avatar_editor', '이미지 파일을 처리할 수 없습니다.');
        }

        $resized = $editor->resize(self::AVATAR_SIZE, self::AVATAR_SIZE, true);
        if (is_wp_error($resized)) {
            return new WP_Error('pkb_avatar_resize', '프로필 사진 크기를 조정하지 못했습니다.');
        }

        $filename = sprintf('user-%d-%d.%s', $user_id, time(), $extension);
        $path = trailingslashit($avatar_dir) . $filename;
        $saved = $editor->save($path, $mime);
        if (is_wp_error($saved) || empty($saved['path'])) {
            return new WP_Error('pkb_avatar_save', '프로필 사진을 저장하지 못했습니다.');
        }

        $this->delete_user_avatar($user_id);
        update_user_meta($user_id, 'pkb_avatar_path', $saved['path']);
        update_user_meta($user_id, 'pkb_avatar_url', trailingslashit($upload_dir['baseurl']) . 'pkb-avatars/' . basename($saved['path']));
        update_user_meta($user_id, 'pkb_avatar_updated_at', current_time('mysql'));

        return true;
    }

    private function avatar_extension_for_mime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => '',
        };
    }

    public function delete_user_avatar(int $user_id): void
    {
        $path = (string) get_user_meta($user_id, 'pkb_avatar_path', true);
        if ($path && file_exists($path)) {
            wp_delete_file($path);
        }

        delete_user_meta($user_id, 'pkb_avatar_path');
        delete_user_meta($user_id, 'pkb_avatar_url');
        delete_user_meta($user_id, 'pkb_avatar_updated_at');
        delete_user_meta($user_id, 'pkb_avatar_upload_timestamps');
        delete_user_meta($user_id, 'pkb_avatar_rate_limited_at');
    }

    public function filter_avatar_data(array $args, $id_or_email): array
    {
        $user_id = $this->avatar_user_id($id_or_email);
        if (!$user_id) {
            return $args;
        }

        $url = (string) get_user_meta($user_id, 'pkb_avatar_url', true);
        if (!$url) {
            return $args;
        }

        $args['url'] = esc_url_raw($url);
        $args['found_avatar'] = true;

        return $args;
    }

    private function avatar_user_id($id_or_email): int
    {
        if (is_numeric($id_or_email)) {
            return absint($id_or_email);
        }

        if ($id_or_email instanceof WP_User) {
            return (int) $id_or_email->ID;
        }

        if ($id_or_email instanceof WP_Post) {
            return (int) $id_or_email->post_author;
        }

        if ($id_or_email instanceof WP_Comment) {
            if ((int) $id_or_email->user_id > 0) {
                return (int) $id_or_email->user_id;
            }

            $user = get_user_by('email', $id_or_email->comment_author_email);
            return $user ? (int) $user->ID : 0;
        }

        if (is_string($id_or_email) && is_email($id_or_email)) {
            $user = get_user_by('email', $id_or_email);
            return $user ? (int) $user->ID : 0;
        }

        return 0;
    }

    public function append_account_nav(string $items, stdClass $args): string
    {
        if (is_user_logged_in()) {
            return $items . '<li class="menu-item"><a href="' . esc_url(home_url('/account/')) . '">마이페이지</a></li>';
        }

        return $items . '<li class="menu-item"><a href="' . esc_url(home_url('/login/')) . '">로그인</a></li>';
    }

    public function shortcode_register(): string
    {
        if (is_user_logged_in()) {
            return '<p class="pkb-form-message">이미 로그인되어 있습니다.</p>';
        }

        ob_start();
        $this->message();
        ?>
        <form class="pkb-form" method="post">
            <?php wp_nonce_field('pkb_registration_register'); ?>
            <input type="hidden" name="pkb_registration_action" value="register">
            <?php echo $this->honeypot_field(); ?>
            <label>아이디 <input name="username" autocomplete="username" required></label>
            <label>이메일 <input type="email" name="email" autocomplete="email" required></label>
            <label>비밀번호 <input type="password" name="password" autocomplete="new-password" minlength="10" required></label>
            <div class="pkb-consent-box" aria-label="회원가입 필수 동의">
                <label class="pkb-checkbox-label">
                    <input type="checkbox" name="terms_accepted" value="1" required>
                    <span><a href="<?php echo esc_url(home_url('/terms-of-service/')); ?>" target="_blank" rel="noopener">이용약관</a>에 동의합니다.</span>
                </label>
                <label class="pkb-checkbox-label">
                    <input type="checkbox" name="privacy_accepted" value="1" required>
                    <span><a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>" target="_blank" rel="noopener">개인정보처리방침</a>에 동의합니다.</span>
                </label>
            </div>
            <p class="pkb-form-note">가입 후 이메일 인증을 완료해야 로그인할 수 있습니다.</p>
            <button class="pkb-auth-button" type="submit">회원가입</button>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    public function shortcode_login(): string
    {
        if (is_user_logged_in()) {
            return '<p class="pkb-form-message">이미 로그인되어 있습니다.</p>';
        }

        ob_start();
        $this->message();
        ?>
        <form class="pkb-form" method="post">
            <?php wp_nonce_field('pkb_registration_login'); ?>
            <input type="hidden" name="pkb_registration_action" value="login">
            <?php $redirect_to = esc_url_raw(wp_unslash($_GET['redirect_to'] ?? '')); ?>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to !== '' ? wp_validate_redirect($redirect_to, home_url('/')) : home_url('/')); ?>">
            <?php echo $this->honeypot_field(); ?>
            <label>아이디 또는 이메일 <input name="log" autocomplete="username" required></label>
            <label>비밀번호 <input type="password" name="pwd" autocomplete="current-password" required></label>
            <label class="pkb-checkbox-label"><input type="checkbox" name="rememberme" value="1"> 로그인 유지</label>
            <button class="pkb-auth-button" type="submit">로그인</button>
            <p class="pkb-form-note"><a href="<?php echo esc_url(home_url('/lost-password/')); ?>">비밀번호를 잊으셨나요?</a></p>
            <p class="pkb-form-note"><a href="<?php echo esc_url(home_url('/register/')); ?>">아이디가 없으신가요?</a></p>
        </form>
        <form class="pkb-form" method="post">
            <?php wp_nonce_field('pkb_registration_resend_verification'); ?>
            <input type="hidden" name="pkb_registration_action" value="resend_verification">
            <?php echo $this->honeypot_field(); ?>
            <label>인증 메일 재발송 <input type="email" name="email" autocomplete="email" required></label>
            <button class="pkb-auth-button" type="submit">재발송</button>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    public function shortcode_lost_password(): string
    {
        if (is_user_logged_in()) {
            return '<p class="pkb-form-message">이미 로그인되어 있습니다.</p>';
        }

        ob_start();
        $this->message();
        ?>
        <form class="pkb-form" method="post">
            <?php wp_nonce_field('pkb_registration_lost_password'); ?>
            <input type="hidden" name="pkb_registration_action" value="lost_password">
            <?php echo $this->honeypot_field(); ?>
            <label>이메일 <input type="email" name="user_login" autocomplete="email" required></label>
            <p class="pkb-form-note">계정이 존재하면 비밀번호 재설정 링크를 이메일로 보내드립니다.</p>
            <button class="pkb-auth-button" type="submit">재설정 메일 보내기</button>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    public function shortcode_reset_password(): string
    {
        if (is_user_logged_in()) {
            return '<p class="pkb-form-message">이미 로그인되어 있습니다.</p>';
        }

        $login = sanitize_text_field(wp_unslash($_GET['login'] ?? ''));
        $key = sanitize_text_field(wp_unslash($_GET['key'] ?? ''));
        if ($login === '' || $key === '') {
            return '<p class="pkb-form-message pkb-message-error">비밀번호 재설정 링크가 유효하지 않습니다.</p>';
        }

        ob_start();
        $this->message();
        ?>
        <form class="pkb-form" method="post">
            <?php wp_nonce_field('pkb_registration_reset_password'); ?>
            <input type="hidden" name="pkb_registration_action" value="reset_password">
            <input type="hidden" name="login" value="<?php echo esc_attr($login); ?>">
            <input type="hidden" name="key" value="<?php echo esc_attr($key); ?>">
            <label>새 비밀번호 <input type="password" name="password" autocomplete="new-password" minlength="10" required></label>
            <label>새 비밀번호 확인 <input type="password" name="password_confirm" autocomplete="new-password" minlength="10" required></label>
            <button class="pkb-auth-button" type="submit">비밀번호 변경</button>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    public function shortcode_account(): string
    {
        if (!is_user_logged_in()) {
            return '<p class="pkb-form-message"><a href="' . esc_url(home_url('/login/')) . '">로그인</a> 후 이용할 수 있습니다.</p>';
        }

        $user = wp_get_current_user();
        $pending_email = sanitize_email((string) get_user_meta($user->ID, 'pkb_pending_email', true));
        ob_start();
        $this->message();
        ?>
        <div class="pkb-account-layout">
            <section class="pkb-account-section">
                <h2>계정</h2>
                <p><strong><?php echo esc_html($user->display_name ?: $user->user_login); ?></strong></p>
                <p class="pkb-meta"><?php echo esc_html($user->user_email); ?></p>
                <?php if ($pending_email) : ?>
                    <p class="pkb-form-note">변경 대기 중인 이메일: <?php echo esc_html($pending_email); ?></p>
                <?php endif; ?>
                <p><a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">로그아웃</a></p>
            </section>

            <form class="pkb-form pkb-account-form" method="post">
                <?php wp_nonce_field('pkb_registration_update_profile'); ?>
                <input type="hidden" name="pkb_registration_action" value="update_profile">
                <h2>프로필</h2>
                <label>아이디 <input value="<?php echo esc_attr($user->user_login); ?>" disabled></label>
                <label>이메일 <input type="email" name="email" value="<?php echo esc_attr($user->user_email); ?>" autocomplete="email" required></label>
                <div class="pkb-form-grid">
                    <label>이름 <input name="first_name" value="<?php echo esc_attr($user->first_name); ?>" autocomplete="given-name"></label>
                    <label>성 <input name="last_name" value="<?php echo esc_attr($user->last_name); ?>" autocomplete="family-name"></label>
                </div>
                <label>닉네임 <input name="nickname" value="<?php echo esc_attr($user->nickname ?: $user->user_login); ?>" required></label>
                <label>표시 이름 <input name="display_name" value="<?php echo esc_attr($user->display_name ?: $user->user_login); ?>" required></label>
                <label>웹사이트 <input type="url" name="user_url" value="<?php echo esc_attr($user->user_url); ?>" autocomplete="url"></label>
                <label>소개 <textarea name="description" rows="5"><?php echo esc_textarea($user->description); ?></textarea></label>
                <p class="pkb-form-note">이메일을 바꾸면 새 주소 확인 후 실제로 변경됩니다.</p>
                <button class="pkb-account-button pkb-account-button-primary" type="submit">프로필 저장</button>
            </form>

            <section class="pkb-account-section">
                <h2>프로필 사진</h2>
                <div class="pkb-avatar-row">
                    <?php echo get_avatar($user->ID, 72, '', '', ['class' => 'pkb-account-avatar']); ?>
                    <div>
                        <form class="pkb-avatar-form" method="post" enctype="multipart/form-data">
                            <?php wp_nonce_field('pkb_registration_upload_avatar'); ?>
                            <input type="hidden" name="pkb_registration_action" value="upload_avatar">
                            <label>이미지 선택 <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required></label>
                            <p class="pkb-form-note">JPG, PNG, WebP. 2MB 이하. 업로드 후 192x192 프로필 사진으로 저장됩니다.</p>
                            <button class="pkb-account-button pkb-account-button-primary" type="submit">프로필 사진 변경</button>
                        </form>
                        <?php if (get_user_meta($user->ID, 'pkb_avatar_url', true)) : ?>
                            <form class="pkb-avatar-delete-form" method="post">
                                <?php wp_nonce_field('pkb_registration_delete_avatar'); ?>
                                <input type="hidden" name="pkb_registration_action" value="delete_avatar">
                                <button class="pkb-account-button pkb-account-button-danger-outline" type="submit">프로필 사진 삭제</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <form class="pkb-form pkb-account-form" method="post">
                <?php wp_nonce_field('pkb_registration_change_password'); ?>
                <input type="hidden" name="pkb_registration_action" value="change_password">
                <h2>비밀번호</h2>
                <label>현재 비밀번호 <input type="password" name="current_password" autocomplete="current-password" required></label>
                <label>새 비밀번호 <input type="password" name="new_password" autocomplete="new-password" minlength="10" required></label>
                <label>새 비밀번호 확인 <input type="password" name="confirm_password" autocomplete="new-password" minlength="10" required></label>
                <button class="pkb-account-button pkb-account-button-primary" type="submit">비밀번호 변경</button>
            </form>

            <?php if (class_exists('Two_Factor_Core')) : ?>
                <section class="pkb-account-section pkb-account-action-section">
                    <h2>2단계 인증</h2>
                    <p class="pkb-form-note">2단계 인증(2FA)은 비밀번호 외에 추가 인증 수단을 요구해 계정을 보호합니다.</p>
                    <div class="pkb-account-action-row">
                        <span class="pkb-two-factor-status <?php echo esc_attr($this->two_factor_status_class($user)); ?>">현재 상태 : <?php echo esc_html($this->two_factor_status_text($user)); ?></span>
                        <a class="pkb-account-button pkb-account-button-primary" href="<?php echo esc_url(home_url('/account-2fa/')); ?>">설정하기</a>
                    </div>
                </section>
            <?php endif; ?>

            <form class="pkb-form pkb-account-form" method="post">
                <?php wp_nonce_field('pkb_registration_destroy_other_sessions'); ?>
                <input type="hidden" name="pkb_registration_action" value="destroy_other_sessions">
                <h2>로그인 세션</h2>
                <p class="pkb-form-note">현재 브라우저는 유지하고, 다른 기기나 브라우저의 로그인 세션을 모두 종료합니다.</p>
                <button class="pkb-account-button pkb-account-button-secondary" type="submit">다른 모든 세션 로그아웃</button>
            </form>

            <section class="pkb-account-section">
                <h2>좋아요한 글</h2>
                <?php echo wp_kses(apply_filters('pkb_liked_posts_markup', '<p class="pkb-meta">좋아요한 글이 없습니다.</p>', $user->ID), $this->account_markup_allowed_html()); ?>
            </section>

            <section class="pkb-account-section">
                <h2>내 댓글</h2>
                <?php echo wp_kses(apply_filters('pkb_user_comments_markup', '<p class="pkb-meta">작성한 댓글이 없습니다.</p>', $user->ID), $this->account_markup_allowed_html()); ?>
            </section>

            <form class="pkb-form pkb-account-form" method="post" onsubmit="return confirm('계정과 작성 댓글, 좋아요를 즉시 삭제합니다. 계속할까요?');">
                <?php wp_nonce_field('pkb_registration_delete_account'); ?>
                <input type="hidden" name="pkb_registration_action" value="delete_account">
                <h2>회원탈퇴</h2>
                <p class="pkb-form-note">탈퇴하면 계정, 작성 댓글, 좋아요 데이터가 즉시 삭제됩니다.</p>
                <button class="pkb-account-button pkb-account-button-danger" type="submit">회원탈퇴</button>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function shortcode_account_2fa(): string
    {
        if (!is_user_logged_in()) {
            return '<p class="pkb-form-message"><a href="' . esc_url(home_url('/login/')) . '">로그인</a> 후 이용할 수 있습니다.</p>';
        }

        $user = wp_get_current_user();
        if (!class_exists('Two_Factor_Core')) {
            return '<p class="pkb-form-message pkb-message-error">2단계 인증 설정 권한이 없습니다.</p>';
        }

        ob_start();
        $this->message();
        ?>
        <div class="pkb-account-layout">
            <p class="pkb-form-note"><a href="<?php echo esc_url(home_url('/account/')); ?>">마이페이지로 돌아가기</a></p>
            <form class="pkb-form pkb-account-form pkb-two-factor-form" method="post">
                <?php wp_nonce_field('pkb_registration_update_2fa'); ?>
                <input type="hidden" name="pkb_registration_action" value="update_2fa">
                <?php $this->render_two_factor_options($user); ?>
                <button class="pkb-account-button pkb-account-button-primary" type="submit">2단계 인증 저장</button>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function require_current_user(): WP_User
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(home_url('/login/'));
            exit;
        }

        return wp_get_current_user();
    }

    private function render_two_factor_options(WP_User $user): void
    {
        if (!class_exists('Two_Factor_Core')) {
            return;
        }

        $this->ensure_staff_two_factor_for_user($user);
        echo '<section class="pkb-account-section pkb-two-factor-section">';
        Two_Factor_Core::user_two_factor_options($user);
        echo '</section>';
    }

    private function two_factor_status_text(WP_User $user): string
    {
        if (!class_exists('Two_Factor_Core')) {
            return '사용 불가';
        }

        $this->ensure_staff_two_factor_for_user($user);
        $enabled = get_user_meta($user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true);
        $enabled = is_array($enabled) ? array_values(array_filter($enabled)) : [];
        if ($enabled === []) {
            return '비활성화';
        }

        $labels = [
            'Two_Factor_Email' => '이메일',
            'Two_Factor_Totp' => '인증 앱',
            'Two_Factor_Backup_Codes' => '복구 코드',
        ];

        $enabled_labels = [];
        foreach ($enabled as $provider) {
            $enabled_labels[] = $labels[$provider] ?? $provider;
        }

        return '활성화됨 (' . implode(', ', $enabled_labels) . ')';
    }

    private function two_factor_status_class(WP_User $user): string
    {
        if (!class_exists('Two_Factor_Core')) {
            return 'pkb-two-factor-status-unavailable';
        }

        $this->ensure_staff_two_factor_for_user($user);
        $enabled = get_user_meta($user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true);
        $enabled = is_array($enabled) ? array_values(array_filter($enabled)) : [];

        return $enabled === [] ? 'pkb-two-factor-status-inactive' : 'pkb-two-factor-status-active';
    }

    private function account_markup_allowed_html(): array
    {
        $allowed = wp_kses_allowed_html('post');
        $allowed['form'] = [
            'class' => true,
            'method' => true,
            'action' => true,
        ];
        $allowed['input'] = [
            'type' => true,
            'name' => true,
            'value' => true,
        ];
        $allowed['textarea'] = [
            'name' => true,
            'rows' => true,
            'required' => true,
        ];
        $allowed['button'] = [
            'class' => true,
            'type' => true,
        ];
        $allowed['details'] = [
            'class' => true,
            'open' => true,
        ];
        $allowed['summary'] = [
            'class' => true,
        ];

        return $allowed;
    }

    private function redirect_with_message(string $url, string $message, string $type = 'info'): void
    {
        $this->set_flash_message($message, $type);
        wp_safe_redirect(remove_query_arg(['pkb_message', 'pkb_type'], $url));
        exit;
    }

    private function message(): void
    {
        $flash = $this->consume_flash_message();
        if ($flash === null && isset($_GET['pkb_message'])) {
            $flash = [
                'message' => sanitize_text_field(wp_unslash($_GET['pkb_message'])),
                'type' => sanitize_html_class(wp_unslash($_GET['pkb_type'] ?? 'info')),
            ];
        }

        if ($flash === null) {
            return;
        }

        echo '<p class="pkb-form-message pkb-message-' . esc_attr($flash['type']) . '">' . esc_html($flash['message']) . '</p>';
    }

    private function set_flash_message(string $message, string $type = 'info'): void
    {
        $payload = base64_encode((string) wp_json_encode([
            'message' => sanitize_text_field($message),
            'type' => sanitize_html_class($type ?: 'info'),
        ]));

        $this->set_flash_cookie($payload, time() + 300);
        $_COOKIE[self::FLASH_COOKIE] = $payload;
    }

    private function consume_flash_message(): ?array
    {
        $payload = isset($_COOKIE[self::FLASH_COOKIE]) ? (string) wp_unslash($_COOKIE[self::FLASH_COOKIE]) : '';
        if ($payload === '') {
            return null;
        }

        $this->set_flash_cookie('', time() - 3600);
        unset($_COOKIE[self::FLASH_COOKIE]);

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }

        $data = json_decode($decoded, true);
        if (!is_array($data) || empty($data['message'])) {
            return null;
        }

        return [
            'message' => sanitize_text_field((string) $data['message']),
            'type' => sanitize_html_class((string) ($data['type'] ?? 'info')),
        ];
    }

    private function set_flash_cookie(string $value, int $expires): void
    {
        $options = [
            'expires' => $expires,
            'path' => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) {
            $options['domain'] = COOKIE_DOMAIN;
        }

        setcookie(self::FLASH_COOKIE, $value, $options);
    }
}

register_activation_hook(__FILE__, ['PKB_Registration', 'activate']);
register_deactivation_hook(__FILE__, ['PKB_Registration', 'deactivate']);
PKB_Registration::instance();
