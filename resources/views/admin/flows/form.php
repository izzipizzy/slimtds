<?php
/** @var \App\Admin\Repository\Campaign $campaign */
/** @var ?\App\Admin\Repository\Flow $flow */
/** @var list<\App\Admin\Repository\Offer> $offers */
/** @var array<string,string> $errors */
/** @var string $csrf_token */
$action   = $flow === null
    ? url('/admin/campaigns/' . $campaign->id . '/flows')
    : url('/admin/campaigns/' . $campaign->id . '/flows/' . $flow->id);
$titleKey = $flow === null ? 'flows.create' : 'flows.edit';

$initialFilters      = $flow?->filters ?? [];
$initialTargetOffers = $flow?->targetOffers ?? [];
$initialSchemaConfig = $flow?->schemaConfig ?? [];

$filterFields = ['country','region','city','asn','device','os','browser','lang','is_bot','bot_name','is_uniq','referer','referer_domain','utm_source','utm_medium','utm_campaign','utm_term','utm_content','time_hour','day_of_week','ip_in_list'];
$filterOps    = ['eq','neq','in','not_in','regex','gt','lt','between','exists'];
$schemas      = [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16];

$offersForJs = array_map(fn ($o) => ['id' => $o->id, 'name' => $o->name], $offers);

// Localized country list for filter value selects/datalist.
$_lang = $lang ?? 'ru';
$countriesRu = [
    'ru'=>'Россия','ua'=>'Украина','by'=>'Беларусь','kz'=>'Казахстан','uz'=>'Узбекистан',
    'us'=>'США','ca'=>'Канада','mx'=>'Мексика','br'=>'Бразилия','ar'=>'Аргентина',
    'co'=>'Колумбия','cl'=>'Чили','pe'=>'Перу','ec'=>'Эквадор','ve'=>'Венесуэла',
    'gb'=>'Великобритания','de'=>'Германия','fr'=>'Франция','es'=>'Испания','it'=>'Италия',
    'pl'=>'Польша','cz'=>'Чехия','nl'=>'Нидерланды','be'=>'Бельгия','at'=>'Австрия',
    'ch'=>'Швейцария','se'=>'Швеция','no'=>'Норвегия','fi'=>'Финляндия','dk'=>'Дания',
    'ie'=>'Ирландия','pt'=>'Португалия','gr'=>'Греция','ro'=>'Румыния','bg'=>'Болгария',
    'hu'=>'Венгрия','sk'=>'Словакия','si'=>'Словения','hr'=>'Хорватия','rs'=>'Сербия',
    'ee'=>'Эстония','lv'=>'Латвия','lt'=>'Литва','tr'=>'Турция','il'=>'Израиль',
    'sa'=>'Саудовская Аравия','ae'=>'ОАЭ','eg'=>'Египет','za'=>'ЮАР','ng'=>'Нигерия',
    'in'=>'Индия','pk'=>'Пакистан','bd'=>'Бангладеш','id'=>'Индонезия','my'=>'Малайзия',
    'sg'=>'Сингапур','th'=>'Таиланд','vn'=>'Вьетнам','ph'=>'Филиппины','jp'=>'Япония',
    'kr'=>'Южная Корея','cn'=>'Китай','hk'=>'Гонконг','tw'=>'Тайвань','au'=>'Австралия','nz'=>'Новая Зеландия',
];
$countriesEn = [
    'ru'=>'Russia','ua'=>'Ukraine','by'=>'Belarus','kz'=>'Kazakhstan','uz'=>'Uzbekistan',
    'us'=>'United States','ca'=>'Canada','mx'=>'Mexico','br'=>'Brazil','ar'=>'Argentina',
    'co'=>'Colombia','cl'=>'Chile','pe'=>'Peru','ec'=>'Ecuador','ve'=>'Venezuela',
    'gb'=>'United Kingdom','de'=>'Germany','fr'=>'France','es'=>'Spain','it'=>'Italy',
    'pl'=>'Poland','cz'=>'Czechia','nl'=>'Netherlands','be'=>'Belgium','at'=>'Austria',
    'ch'=>'Switzerland','se'=>'Sweden','no'=>'Norway','fi'=>'Finland','dk'=>'Denmark',
    'ie'=>'Ireland','pt'=>'Portugal','gr'=>'Greece','ro'=>'Romania','bg'=>'Bulgaria',
    'hu'=>'Hungary','sk'=>'Slovakia','si'=>'Slovenia','hr'=>'Croatia','rs'=>'Serbia',
    'ee'=>'Estonia','lv'=>'Latvia','lt'=>'Lithuania','tr'=>'Turkey','il'=>'Israel',
    'sa'=>'Saudi Arabia','ae'=>'UAE','eg'=>'Egypt','za'=>'South Africa','ng'=>'Nigeria',
    'in'=>'India','pk'=>'Pakistan','bd'=>'Bangladesh','id'=>'Indonesia','my'=>'Malaysia',
    'sg'=>'Singapore','th'=>'Thailand','vn'=>'Vietnam','ph'=>'Philippines','jp'=>'Japan',
    'kr'=>'South Korea','cn'=>'China','hk'=>'Hong Kong','tw'=>'Taiwan','au'=>'Australia','nz'=>'New Zealand',
];
$countries = $_lang === 'en' ? $countriesEn : $countriesRu;
?>
<div style="max-width:680px"
     x-data="flowBuilder({
         initialFilters: <?= htmlspecialchars(json_encode($initialFilters, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>,
         initialTargetOffers: <?= htmlspecialchars(json_encode($initialTargetOffers, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>,
         initialSchemaConfig: <?= htmlspecialchars(json_encode($initialSchemaConfig, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>,
         initialSchemaId: <?= (int)($flow?->schemaId ?? 2) ?>,
         initialTargetType: <?= htmlspecialchars(json_encode($flow?->targetType ?? 'offers'), ENT_QUOTES) ?>,
         availableOffers: <?= htmlspecialchars(json_encode($offersForJs, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>
     })">

    <!-- Breadcrumb -->
    <nav style="font-size:0.8rem;color:var(--color-stone-400);font-family:var(--font-sans);margin-bottom:20px">
        <a href="<?= e(url('/admin/campaigns')) ?>" style="color:var(--color-stone-400);text-decoration:none"><?= e(t('campaigns.title')) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows')) ?>" style="color:var(--color-stone-400);text-decoration:none;font-family:var(--font-mono)"><?= e($campaign->slug) ?>/<?= e(t('flows.title')) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <span><?= e(t($titleKey)) ?></span>
    </nav>

    <h1 class="page-title-sm"><?= e(t($titleKey)) ?></h1>

    <form method="post" action="<?= e($action) ?>" @submit="syncHidden()" style="display:flex;flex-direction:column;gap:0">
        <?= csrf_field($csrf_token) ?>

        <!-- Hidden fields that Alpine writes to on submit -->
        <input type="hidden" name="filters" x-ref="filtersField">
        <input type="hidden" name="target_offers" x-ref="targetOffersField">
        <input type="hidden" name="schema_config" x-ref="schemaConfigField">

        <!-- Section: Basic -->
        <div class="form-section">
            <span class="form-section-label"><?= e(t('flows.section_basic')) ?></span>
            <div style="display:flex;flex-direction:column;gap:18px">
                <div>
                    <label class="label-uppercase" for="name"><?= e(t('flows.name')) ?></label>
                    <input id="name" name="name" class="input" required value="<?= e(old('name', $flow?->name ?? '')) ?>">
                    <?php if (isset($errors['name'])): ?><p class="form-error"><?= e(t($errors['name'])) ?></p><?php endif; ?>
                </div>

                <div style="display:grid;grid-template-columns:120px 1fr;gap:16px;align-items:end">
                    <div>
                        <label class="label-uppercase" for="weight"><?= e(t('flows.weight')) ?></label>
                        <input id="weight" name="weight" type="number" class="input" style="font-variant-numeric:tabular-nums"
                               value="<?= (int)old('weight', $flow?->weight ?? 100) ?>">
                    </div>
                    <div>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.875rem;font-family:var(--font-sans);color:var(--color-stone-700);padding-bottom:9px">
                            <input type="checkbox" id="is_active" name="is_active" value="1"
                                   <?= old('is_active', $flow?->isActive ?? true) ? 'checked' : '' ?>>
                            <?= e(t('flows.is_active')) ?>
                        </label>
                    </div>
                </div>

                <?php
                $stickyCur = $flow === null || $flow->stickyOffer === null ? 'inherit' : ($flow->stickyOffer ? 'sticky' : 'rotate');
                $stickyCur = (string)old('sticky_offer', $stickyCur);
                ?>
                <div>
                    <label class="label-uppercase" for="sticky_offer"><?= e(t('flows.sticky_offer')) ?></label>
                    <select id="sticky_offer" name="sticky_offer" class="input" style="max-width:340px">
                        <option value="inherit" <?= $stickyCur === 'inherit' ? 'selected' : '' ?>><?= e(t('flows.sticky_inherit')) ?></option>
                        <option value="sticky"  <?= $stickyCur === 'sticky' ? 'selected' : '' ?>><?= e(t('flows.sticky_on')) ?></option>
                        <option value="rotate"  <?= $stickyCur === 'rotate' ? 'selected' : '' ?>><?= e(t('flows.sticky_rotate')) ?></option>
                    </select>
                    <p style="font-size:0.78rem;color:var(--color-stone-500);margin:4px 0 0;font-family:var(--font-sans)"><?= e(t('flows.sticky_hint')) ?></p>
                </div>
            </div>
        </div>

        <!-- Section: Filters -->
        <div class="form-section">
            <span class="form-section-label"><?= e(t('flows.filters')) ?></span>
            <p style="font-size:0.8rem;color:var(--color-stone-400);font-family:var(--font-sans);margin-bottom:12px"><?= e(t('flows.no_filters')) ?></p>

            <template x-for="(group, gi) in filters" :key="gi">
                <div style="border:1px solid var(--color-stone-200);border-radius:4px;padding:12px;margin-bottom:10px;background:var(--color-stone-50)">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                        <span style="font-size:0.7rem;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-stone-400);font-family:var(--font-sans)">AND group #<span x-text="gi+1"></span></span>
                        <button type="button" @click="removeGroup(gi)"
                                style="font-size:0.8rem;color:var(--color-stone-400);background:none;border:none;cursor:pointer;padding:0 4px">&times;</button>
                    </div>
                    <template x-for="(cond, ci) in group" :key="ci">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                            <select x-model="cond.field" class="input" style="max-width:180px;font-size:0.8rem">
                                <?php foreach ($filterFields as $ff): ?>
                                    <option value="<?= e($ff) ?>"><?= e(t('filter_fields.' . $ff)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select x-model="cond.op" class="input" style="max-width:100px;font-size:0.8rem">
                                <?php foreach ($filterOps as $op): ?>
                                    <option value="<?= e($op) ?>"><?= e(t('filter_ops.' . $op)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <!-- Value control swaps based on field type -->
                            <template x-if="cond.field === 'is_bot'">
                                <select x-model="cond.value" class="input" style="flex:1;font-size:0.8rem">
                                    <option value=""><?= e(t('filter_values.placeholder.select')) ?></option>
                                    <option value="1"><?= e(t('filter_values.is_bot.yes_')) ?></option>
                                    <option value="0"><?= e(t('filter_values.is_bot.no_')) ?></option>
                                </select>
                            </template>
                            <template x-if="cond.field === 'is_uniq'">
                                <select x-model="cond.value" class="input" style="flex:1;font-size:0.8rem">
                                    <option value=""><?= e(t('filter_values.placeholder.select')) ?></option>
                                    <option value="1"><?= e(t('filter_values.is_uniq.yes_')) ?></option>
                                    <option value="0"><?= e(t('filter_values.is_uniq.no_')) ?></option>
                                </select>
                            </template>
                            <template x-if="cond.field === 'day_of_week'">
                                <select x-model="cond.value" class="input" style="flex:1;font-size:0.8rem">
                                    <option value=""><?= e(t('filter_values.placeholder.day')) ?></option>
                                    <?php for ($d = 0; $d <= 6; $d++): ?>
                                        <option value="<?= $d ?>"><?= e(t('filter_values.dow.' . $d)) ?> (<?= $d ?>)</option>
                                    <?php endfor; ?>
                                </select>
                            </template>
                            <template x-if="cond.field === 'device'">
                                <select x-model="cond.value" class="input" style="flex:1;font-size:0.8rem">
                                    <option value=""><?= e(t('filter_values.placeholder.device')) ?></option>
                                    <option value="mobile">mobile</option>
                                    <option value="tablet">tablet</option>
                                    <option value="desktop">desktop</option>
                                </select>
                            </template>
                            <template x-if="cond.field === 'os'">
                                <select x-model="cond.value" class="input" style="flex:1;font-size:0.8rem">
                                    <option value=""><?= e(t('filter_values.placeholder.os')) ?></option>
                                    <option value="iOS">iOS</option>
                                    <option value="Android">Android</option>
                                    <option value="Windows">Windows</option>
                                    <option value="macOS">macOS</option>
                                    <option value="Linux">Linux</option>
                                </select>
                            </template>
                            <template x-if="cond.field === 'browser'">
                                <select x-model="cond.value" class="input" style="flex:1;font-size:0.8rem">
                                    <option value=""><?= e(t('filter_values.placeholder.browser')) ?></option>
                                    <option value="Chrome">Chrome</option>
                                    <option value="Safari">Safari</option>
                                    <option value="Firefox">Firefox</option>
                                    <option value="Edge">Edge</option>
                                    <option value="Opera">Opera</option>
                                </select>
                            </template>
                            <template x-if="cond.field === 'lang'">
                                <select x-model="cond.value" class="input" style="flex:1;font-size:0.8rem;font-family:var(--font-mono)">
                                    <option value=""><?= e(t('filter_values.placeholder.lang')) ?></option>
                                    <option value="ru">ru · Русский</option>
                                    <option value="en">en · English</option>
                                    <option value="uk">uk · Українська</option>
                                    <option value="es">es · Español</option>
                                    <option value="pt">pt · Português</option>
                                    <option value="de">de · Deutsch</option>
                                    <option value="fr">fr · Français</option>
                                    <option value="it">it · Italiano</option>
                                    <option value="pl">pl · Polski</option>
                                    <option value="tr">tr · Türkçe</option>
                                    <option value="ar">ar · العربية</option>
                                    <option value="zh">zh · 中文</option>
                                    <option value="ja">ja · 日本語</option>
                                    <option value="ko">ko · 한국어</option>
                                    <option value="hi">hi · हिन्दी</option>
                                </select>
                            </template>
                            <template x-if="cond.field === 'country'">
                                <!-- `in`/`not_in` take a comma-separated list (FilterCompiler::inList
                                     splits on commas), so the single-ISO-code cap must lift for them. -->
                                <input x-model="cond.value" list="list-country" class="input" style="flex:1;font-size:0.8rem;font-family:var(--font-mono)"
                                       :placeholder="isListOp(cond.op) ? '<?= e(t('filter_values.placeholder.country_list')) ?>' : '<?= e(t('filter_values.placeholder.country')) ?>'"
                                       autocomplete="off" :maxlength="isListOp(cond.op) ? null : 2">
                            </template>
                            <template x-if="!['is_bot','is_uniq','day_of_week','device','os','browser','lang','country'].includes(cond.field)">
                                <input x-model="cond.value" :list="'list-' + cond.field" class="input" style="flex:1;font-size:0.8rem;font-family:var(--font-mono)" placeholder="value">
                            </template>
                            <button type="button" @click="removeCondition(gi, ci)"
                                    style="font-size:0.9rem;color:var(--color-stone-400);background:none;border:none;cursor:pointer;padding:0 4px">&times;</button>
                        </div>
                    </template>
                    <button type="button" @click="addCondition(gi)" class="btn-ghost" style="font-size:0.75rem">
                        <?= e(t('flows.add_condition')) ?>
                    </button>
                </div>
            </template>

            <button type="button" @click="addGroup()" class="btn-secondary" style="font-size:0.8rem">
                <?= e(t('flows.add_group')) ?>
            </button>
        </div>

        <!-- Section: Target -->
        <div class="form-section">
            <span class="form-section-label"><?= e(t('flows.target')) ?></span>

            <div style="margin-bottom:16px">
                <label style="display:block;font-size:0.75rem;font-weight:500;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-stone-500);font-family:var(--font-sans);margin-bottom:6px"><?= e(t('flows.target_type')) ?></label>
                <select name="target_type" x-model="targetType" class="input" style="max-width:240px">
                    <option value="offers"><?= e(t('flows.target_type_offers')) ?></option>
                    <option value="none"><?= e(t('flows.target_type_none')) ?></option>
                </select>
            </div>

            <div x-show="targetType === 'offers'">
                <?php if (empty($offers)): ?>
                    <div style="font-size:0.875rem;color:var(--color-warn);background:var(--color-surface)beb;border:1px solid var(--color-warn-border);padding:10px 14px;border-radius:4px;font-family:var(--font-sans)">
                        <?= e(t('flows.no_offers')) ?>
                    </div>
                <?php else: ?>
                    <template x-for="(to, idx) in targetOffers" :key="idx">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                            <select x-model="to.offer_id" class="input" style="flex:1;font-size:0.8rem">
                                <option value="">—</option>
                                <?php foreach ($offers as $o): ?>
                                    <option value="<?= e($o->id) ?>"><?= e($o->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" x-model.number="to.weight" min="0" max="100"
                                   class="input" style="width:80px;font-size:0.8rem;font-variant-numeric:tabular-nums;font-family:var(--font-mono)" placeholder="%">
                            <button type="button" @click="removeTarget(idx)"
                                    style="font-size:0.9rem;color:var(--color-stone-400);background:none;border:none;cursor:pointer;padding:0 4px">&times;</button>
                        </div>
                    </template>
                    <button type="button" @click="addTarget()" class="btn-secondary" style="font-size:0.8rem;margin-bottom:10px;display:inline-flex;align-items:center;gap:6px">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        <?= e(t('flows.add_offer')) ?>
                    </button>

                    <p style="font-size:0.8rem;font-family:var(--font-mono)"
                       :style="weightsSum === 100 ? 'color:var(--color-stone-400)' : 'color:var(--color-warn)'">
                        Sum: <span x-text="weightsSum"></span>%
                        <span x-show="weightsSum !== 100"> — <?= e(t('flows.weights_sum_warning')) ?></span>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Section: Schema -->
        <div class="form-section">
            <span class="form-section-label"><?= e(t('flows.schema')) ?></span>

            <div style="margin-bottom:16px">
                <label class="label-uppercase" for="schema_id"><?= e(t('flows.schema')) ?></label>
                <select id="schema_id" name="schema_id" x-model.number="schemaId" class="input" style="max-width:320px">
                    <?php foreach ($schemas as $s): ?>
                        <option value="<?= (int)$s ?>"><?= e(t('schemas.' . $s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div x-show="schemaId === 14" style="margin-bottom:12px">
                <label style="display:block;font-size:0.75rem;font-weight:500;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-stone-500);font-family:var(--font-sans);margin-bottom:6px"><?= e(t('flows.status_code')) ?></label>
                <input x-model.number="schemaConfig.status_code" type="number" class="input" style="max-width:100px;font-family:var(--font-mono)" placeholder="403">
            </div>

            <div x-show="[9,10,11,15].includes(schemaId)" style="margin-bottom:12px">
                <label style="display:block;font-size:0.75rem;font-weight:500;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-stone-500);font-family:var(--font-sans);margin-bottom:6px"><?= e(t('flows.body')) ?></label>
                <textarea x-model="schemaConfig.body" rows="5" class="input" style="font-family:var(--font-mono);font-size:0.8rem"></textarea>
            </div>

            <div x-show="schemaId === 12">
                <label class="label-uppercase"><?= e(t('flows.url')) ?></label>
                <input x-model="schemaConfig.url" class="input input-mono" placeholder="https://origin.example.com/...">
            </div>

            <!-- Static redirect URL — fallback when target_type=none (no offer to draw URL from) -->
            <div x-show="[1,2,3,4,5,6,7,8,16].includes(schemaId) && targetType === 'none'" style="margin-top:6px">
                <label class="label-uppercase"><?= e(t('flows.redirect_url')) ?></label>
                <input x-model="schemaConfig.url" class="input input-mono" placeholder="https://example.com/landing">
                <p class="form-help"><?= e(t('flows.redirect_url_hint')) ?></p>
            </div>

            <!-- JS-redirect tuning -->
            <div x-show="schemaId === 16" style="margin-top:6px;display:flex;gap:12px;flex-wrap:wrap">
                <div>
                    <label class="label-uppercase"><?= e(t('flows.js_mode')) ?></label>
                    <select x-model="schemaConfig.mode" class="input" style="width:160px">
                        <option value="replace"><?= e(t('flows.js_mode_replace')) ?></option>
                        <option value="assign"><?= e(t('flows.js_mode_assign')) ?></option>
                    </select>
                </div>
                <div>
                    <label class="label-uppercase"><?= e(t('flows.delay_ms')) ?></label>
                    <input x-model.number="schemaConfig.delay" type="number" min="0" max="60000" class="input input-mono" style="width:120px" placeholder="0">
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div style="display:flex;gap:10px;margin-top:28px;padding-top:20px;border-top:1px solid var(--color-stone-100)">
            <button type="submit" class="btn"><?= e(t('campaigns.save')) ?></button>
            <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows')) ?>" class="btn-secondary"><?= e(t('form.cancel')) ?></a>
        </div>

        <!-- Debug preview -->
        <details style="margin-top:16px">
            <summary style="cursor:pointer;font-size:0.75rem;color:var(--color-stone-400);font-family:var(--font-mono)"><?= e(t('flows.raw_preview')) ?></summary>
            <pre x-text="JSON.stringify({filters, targetOffers, schemaConfig}, null, 2)"
                 style="margin-top:8px;background:var(--color-stone-900);color:var(--color-stone-100);padding:12px;border-radius:4px;font-size:0.75rem;overflow:auto;font-family:var(--font-mono)"></pre>
        </details>
    </form>

    <datalist id="list-country">
        <?php foreach ($countries as $code => $name): ?>
            <option value="<?= e($code) ?>" label="<?= e($name) ?>"></option>
        <?php endforeach; ?>
    </datalist>
    <datalist id="list-device">
        <option value="mobile"></option><option value="tablet"></option><option value="desktop"></option>
    </datalist>
    <datalist id="list-os">
        <option value="iOS"></option><option value="Android"></option><option value="Windows"></option><option value="macOS"></option><option value="Linux"></option>
    </datalist>
    <datalist id="list-browser">
        <option value="Chrome"></option><option value="Safari"></option><option value="Firefox"></option><option value="Edge"></option><option value="Opera"></option>
    </datalist>
    <datalist id="list-lang">
        <option value="ru" label="Русский"></option><option value="en" label="English"></option><option value="uk" label="Українська"></option>
        <option value="de" label="Deutsch"></option><option value="fr" label="Français"></option><option value="es" label="Español"></option>
        <option value="it" label="Italiano"></option><option value="pt" label="Português"></option><option value="pl" label="Polski"></option>
        <option value="tr" label="Türkçe"></option><option value="zh" label="中文"></option><option value="ja" label="日本語"></option>
    </datalist>
    <datalist id="list-is_bot"><option value="1"></option><option value="0"></option></datalist>
    <datalist id="list-is_uniq"><option value="1"></option><option value="0"></option></datalist>
    <datalist id="list-day_of_week">
        <?php for ($dow = 0; $dow <= 6; $dow++): ?><option value="<?= $dow ?>" label="<?= e(t('filter_values.dow.' . $dow)) ?>"></option><?php endfor; ?>
    </datalist>
</div>
