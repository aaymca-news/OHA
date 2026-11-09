<?php

namespace App\Oha;

use App\Models\FormFinding;

/**
 * One sentence per OHA question on why it matters to the movement's health: shown beside
 * a gap, beside the box where a missing answer is typed, and beside the evidence a "Yes"
 * calls for. Drafted for review by the Movement Strengthening team: the wording is
 * changed here, nowhere else.
 */
final class WhyItMatters
{
    /** Questions answered as one: the income and expenditure shares, Vision 2030's elements, the founding statements. */
    private const SHARED = [
        'income' => 'The income mix shows how much the YMCA depends on one source: a single source above half of income is a risk if it stops.',
        'expense' => 'How the money is spent shows whether most of it reaches programmes or goes on running costs.',
        'vision' => 'Each element of Vision 2030 in the plan shows how closely the YMCA follows the direction of the global movement.',
        'statements' => 'The movement’s founding statements keep the YMCA connected to the identity it shares with every YMCA worldwide.',
    ];

    /** An area of improvement left blank, and the form not agreed by its leaders. */
    public const COMMENT = 'Without it, the movement’s own view of what to improve is missing, and the ODP’s priorities rest on the assessor’s reading alone.';

    public const SIGNOFF = 'The form is final only once the National General Secretary and the Board Chair have agreed it, so the movement owns the results.';

    /** Question code => why it matters. */
    public const REASONS = [
        // General information
        'Q101' => 'The legal name ties the assessment to the registered organisation, so the report and the ODP name the right body.',
        'Q102' => 'The country confirms the form belongs to this movement and sets the legal context the answers are read in.',
        'Q103' => 'The code lets the Alliance match this form with World YMCA and Alliance records.',
        'Q104' => 'The registered address is part of the movement’s legal identity and is needed for official correspondence.',
        'Q105' => 'The reporting period dates the figures, so the scores can be compared fairly with the next assessment.',
        'Q106' => 'Legal registration is the basis for opening bank accounts, signing contracts and receiving grants.',
        'Q107' => 'The registration category shows which national rules on governance and reporting the YMCA must follow.',
        'Q108' => 'Tax-exempt status lowers costs, and many donors require it before they give.',
        'Q109' => 'A status recognised by the government can open the way to public partnerships and funding.',
        'Q110' => 'A certification shows partners that the YMCA meets a recognised standard.',
        'Q111' => 'These details help the assessor read the registration answers correctly.',
        'Q112' => 'Whether the YMCA is a federation or a single entity decides which questions apply and how its governance is judged.',
        'Q113' => 'The number of member organisations shows the size of the federation the national body coordinates.',
        'Q114' => 'Members registered as legal entities carry legal duties of their own, which the national body needs to know about.',
        'Q115' => 'Informal groups extend the movement’s reach but sit outside formal accountability, a risk to manage.',
        'Q116' => 'A special situation, such as a dispute or a merger, explains answers that would otherwise look like gaps.',
        'Q117' => 'Dormant members weaken the federation’s governance and its claim to represent the movement nationally.',
        'Q118' => 'The number of branches shows how far the YMCA reaches and how much it manages centrally.',

        // Financial Stability
        'Q201' => 'A budget plans spending within the money available; without one, overspending goes unnoticed until it is too late.',
        'Q202' => 'Comparing actual results with the budget shows problems early, while there is still time to act.',
        'Q203' => 'Internal controls protect the YMCA’s money from error and fraud.',
        'Q204' => 'Funder audits show that grants were spent as agreed, which keeps funders willing to give again.',
        'Q205' => 'An independent annual audit is the strongest assurance to donors and partners that the accounts are true.',
        'Q206' => 'Changing auditors every few years keeps the audit independent; one to five years with the same auditor scores the point.',
        'Q207' => 'Publishing the financial report shows accountability and builds the trust of donors and the public.',
        'Q208' => 'The currency is needed to read every money figure on the form correctly.',
        'Q209' => 'Current assets show what the YMCA could turn into cash within a year to pay its bills.',
        'Q210' => 'Current liabilities are what the YMCA must pay within a year; set against current assets they show whether it can pay them.',
        'Q211' => 'Total income shows the size of the YMCA’s finances and is the base for every share and ratio on the form.',
        'Q212' => 'Total expenditure, set against income, shows whether the YMCA lives within its means.',
        'Q213' => 'The operating balance shows whether the year ended in surplus or deficit; repeated deficits drain the reserves.',
        'Q223' => 'Heavy reliance on international funding leaves the YMCA exposed to decisions taken abroad; local income lasts longer.',
        'Q224' => 'Heavy reliance on international funding leaves the YMCA exposed to decisions taken abroad; local income lasts longer.',
        'Q225' => 'Unrestricted funding pays for core costs and new needs; when nearly all funding is restricted, the core goes underfunded.',
        'Q226' => 'Unrestricted funding pays for core costs and new needs; when nearly all funding is restricted, the core goes underfunded.',
        'Q227' => 'If losing one funder would hurt the YMCA dramatically, it depends too much on that funder.',
        'Q228' => 'A backup plan limits the damage if a major funder stops, so services and staff can continue.',
        'Q238' => 'Being able to cover its costs for two years shows the YMCA can keep going through a difficult period.',
        'Q239' => 'A cash reserve lets the YMCA pay salaries and bills when income is late or falls.',
        'Q240' => 'The size of the reserve, set against monthly spending, shows how long it would last.',
        'Q241' => 'A reserve that lasts more than six months is the benchmark for financial resilience.',
        'Q242' => 'Long-term debt is repaid from future income, which limits what the YMCA can do.',
        'Q243' => 'The amount of debt, set against income and assets, shows whether it is manageable.',
        'Q244' => 'A rise in interest rates can make a manageable debt unaffordable.',
        'Q245' => 'Missed repayments can lead to penalties, the loss of property and the loss of lenders’ trust.',
        'Q246' => 'The cost of fundraising shows how much is spent to raise each unit of income.',
        'Q247' => 'Supporting other YMCAs shows financial strength and solidarity within the movement.',
        'Q248' => 'The amount shows the scale of the support given to other YMCAs.',

        // Governance
        'Q310' => 'The ultimate decision-making body is where final authority, and accountability, lies.',
        'Q311' => 'An unusual structure needs explaining, so the assessor can judge whether authority is clear.',
        'Q312' => 'Who elects the Board shows whether the members can hold the Board to account.',
        'Q313' => 'Constitutional limits on the Board’s size keep it workable and its decisions valid.',
        'Q314' => 'Constitutional limits on the Board’s size keep it workable and its decisions valid.',
        'Q315' => 'A gender-balanced Board brings a wider range of views to its decisions.',
        'Q316' => 'A gender-balanced Board brings a wider range of views to its decisions.',
        'Q317' => 'Recording every gender on the Board shows how inclusive it is.',
        'Q318' => 'Young people on the Board keep the YMCA close to the young people it serves, as Vision 2030 expects.',
        'Q319' => 'New Board members bring fresh ideas; too many at once can weaken continuity.',
        'Q320' => 'Recruiting for skills gives the Board the expertise its strategy needs.',
        'Q321' => 'The approach shows how skills-based recruitment works in practice.',
        'Q322' => 'Terms of under five years keep the Board renewing itself and accountable to the members.',
        'Q323' => 'Term limits for Board members stop the same people holding power indefinitely.',
        'Q324' => 'The length of the limit shows whether it is meaningful.',
        'Q325' => 'A term limit for the President or Chair makes sure leadership changes hands in good time.',
        'Q326' => 'The length of the limit shows whether leadership is renewed in practice.',
        'Q325#2' => 'An annual self-evaluation helps the Board see and fix its own weaknesses.',
        'Q326#2' => 'A Board that meets regularly can oversee the YMCA properly; twice or more a year scores the point.',
        'Q327' => 'An annual appraisal holds the CEO / General Secretary to account and supports their development.',
        'Q328' => 'Keeping the CEO and the Chair separate keeps oversight independent of management.',
        'Q329' => 'A succession plan keeps the YMCA running if the CEO / General Secretary leaves suddenly.',
        'Q330' => 'A temporary plan says who leads in an emergency, so decisions are not stalled.',
        'Q331' => 'A long-term plan prepares the next leader, so the change of leadership is smooth.',
        'Q332' => 'Planning for key properties and assets protects what the YMCA owns when its leadership changes.',
        'Q333' => 'Planning for management risks and liabilities keeps the YMCA from being caught unprepared.',

        // Constitution / Bylaws / Policies
        'Q410' => 'An up-to-date constitution keeps the YMCA within the law and its decisions valid.',
        'Q411' => 'A change to the constitution needs time and care, and changes how the YMCA is governed.',
        'Q412' => 'A conflict of interest policy keeps decisions in the YMCA’s interest, not the personal interest of those deciding.',
        'Q413' => 'A safeguarding policy protects children, young people and vulnerable adults, and most funders require one.',
        'Q414' => 'A whistleblowing policy lets people raise concerns safely, so problems come to light early.',
        'Q415' => 'A diversity, equity and inclusion policy keeps the YMCA open to all, as its mission requires.',
        'Q416' => 'A health and safety policy protects staff, volunteers and participants from harm, and the YMCA from liability.',
        'Q417' => 'A personnel policy sets fair and lawful terms for staff and reduces disputes.',
        'Q418' => 'A financial procedures manual makes sure money is handled the same controlled way every time.',
        'Q419' => 'A gift acceptance policy protects the YMCA from gifts that conflict with its values or bring risk.',
        'Q420' => 'A cybersecurity and AI policy protects the YMCA’s systems and data from misuse and attack.',
        'Q421' => 'A data protection policy keeps personal data safe and the YMCA within data protection law.',
        'Q422' => 'A procurement policy gets value for money and guards against fraud in purchasing.',
        'Q423' => 'A clear nomination and election procedure makes Board elections fair and hard to dispute.',
        'Q424' => 'Membership criteria set out what a local YMCA must meet to belong to the federation.',
        'Q425' => 'A code of conduct for Board members sets the standards they are held to.',
        'Q426' => 'A code of conduct for staff and volunteers sets clear standards of behaviour, especially towards young people.',
        'Q427' => 'Wellbeing and development policies help keep good staff and volunteers, and help them grow.',
        'Q428' => 'Climate policies put Vision 2030’s Sustainable Planet pillar into practice.',
        'Q429' => 'An anti-corruption policy protects the YMCA’s money and reputation.',
        'Q430' => 'A risk management plan helps the YMCA foresee problems and prepare for them.',
        'Q431' => 'Other policies can show good practice that other movements can learn from.',

        // Monitoring & Evaluation
        'Q511' => 'Monitoring projects when funders ask shows the results of those projects.',
        'Q512' => 'Monitoring across the whole organisation shows whether the YMCA as a whole is reaching its aims.',
        'Q513' => 'Evaluating projects shows what worked in each one.',
        'Q514' => 'Evaluating across the organisation turns lessons into better programmes everywhere.',
        'Q515' => 'Recording knowledge keeps what the YMCA has learned when staff and volunteers move on.',
        'Q516' => 'Impact measurement shows the difference the YMCA makes, which donors increasingly ask for.',
        'Q517' => 'Naming the tools shows how impact is actually measured.',
        'Q518' => 'The number of people reached shows the YMCA’s reach and is used in Alliance reporting.',

        // Strategic Planning
        'Q610' => 'A strategic plan sets the direction and priorities that budgets, programmes and staff work towards.',
        'Q611' => 'The date and period show whether the plan is still current.',
        'Q612' => 'Staff who help shape the plan understand it and carry it out.',
        'Q613' => 'Involving the Board makes the plan its own, so it oversees it.',
        'Q614' => 'Involving the members keeps the plan true to what the movement wants.',
        'Q615' => 'Volunteers who are consulted are more committed to delivering the plan.',
        'Q616' => 'Involving young people and communities keeps the plan relevant to those it serves.',
        'Q617' => 'Involving partners aligns the plan with those who fund the YMCA and work alongside it.',
        'Q618' => 'Reviewing the plan every year keeps it relevant as circumstances change.',
        'Q619' => 'An innovation component keeps the YMCA’s work fresh and responsive to new needs.',
        'Q620' => 'Reflecting Vision 2030 aligns the YMCA with the global movement’s shared direction.',
        'Q628' => 'A clear vision and mission tell staff, volunteers and partners what the YMCA is for.',
        'Q629' => 'Stated values guide behaviour and decisions across the YMCA.',
        'Q630' => 'Alignment with the YMCA’s Christian values keeps the movement’s identity intact.',

        // Diversity & Youth
        'Q710' => 'Being open to everyone is at the heart of the YMCA’s mission.',
        'Q711' => 'Young people in decision-making keep the YMCA youth-led, as Vision 2030 expects.',
        'Q712' => 'Gender balance in decision-making brings a fuller range of views.',
        'Q713' => 'Representing other groups keeps the YMCA close to all the communities it serves.',
        'Q714' => 'Procedures for equal participation make sure every Board member’s voice counts.',
        'Q715' => 'The details show how equal participation works in practice.',
        'Q716' => 'Inclusion policies make sure young people, volunteers and staff can take a full part.',
        'Q717' => 'The details show how inclusion works in practice.',

        // Communications & Branding
        'Q810' => 'A public annual report shows what the YMCA achieved and builds the trust of donors and partners.',
        'Q811' => 'An up-to-date website is often the first thing partners and donors see of the YMCA.',
        'Q812' => 'A communications strategy makes sure the YMCA’s messages reach the right people, consistently.',
        'Q813' => 'A dedicated person makes sure communications happen and are done well.',
        'Q814' => 'A registered brand protects the YMCA’s name from misuse.',
        'Q815' => 'Licensing agreements control how member YMCAs use the brand, including if they leave the federation.',

        // Property Management
        'Q910' => 'Owned property is a major asset that needs protecting and managing.',
        'Q911' => 'A list of the properties is where managing and insuring them starts.',
        'Q912' => 'Property that is properly registered is legally secure and hard to lose.',
        'Q913' => 'A known risk to property needs a plan before it leads to a loss.',
        'Q914' => 'The details of the risk show what must be done to protect the property.',
        'Q915' => 'A development plan makes the property serve the mission and earn its keep.',

        // Staff & Volunteer Development
        'Q1001' => 'Without enough volunteers, the operational plan cannot be delivered.',
        'Q1002' => 'Without enough staff, the operational plan cannot be delivered and the staff there burn out.',
        'Q1003' => 'Training keeps staff and volunteers skilled and safe in their work.',
        'Q1004' => 'Official contracts protect staff rights and keep the YMCA within labour law.',
        'Q1005' => 'Social security and health cover protect staff and help the YMCA keep them.',
        'Q1006' => 'The context explains staffing arrangements that the figures alone do not show.',
        'Q1007' => 'Job descriptions make roles and responsibilities clear and are the basis of appraisals.',
        'Q1008' => 'The total staff shows the YMCA’s capacity to deliver its work.',
        'Q1009' => 'Core staff give the YMCA stability beyond any single project.',
        'Q1010' => 'Temporary staff add flexibility, but not lasting capacity.',
        'Q1011' => 'Project staff depend on project funding and may leave when it ends.',
        'Q1012' => 'Young staff keep the YMCA close to the young people it serves.',
        'Q1013' => 'Volunteers are central to the YMCA’s work and its reach.',
    ];

    /** Why the question with this code matters, or null if there is no sentence for it. */
    public static function for(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::REASONS[$code] ?? match (true) {
            in_array($code, FormDefinition::INCOME, true) => self::SHARED['income'],
            in_array($code, FormDefinition::EXPENSE, true) => self::SHARED['expense'],
            in_array($code, ['Q621', 'Q622', 'Q623', 'Q624', 'Q625', 'Q626', 'Q627'], true) => self::SHARED['vision'],
            in_array($code, ['Q631', 'Q632', 'Q633', 'Q634', 'Q635'], true) => self::SHARED['statements'],
            default => null,
        };
    }

    /** Why a gap or a warning matters: its question's sentence, or the area of improvement's, or the sign-off's. */
    public static function forFinding(FormFinding $finding): ?string
    {
        return match ($finding->rule) {
            FindingRule::CommentMissing->value => self::COMMENT,
            FindingRule::SignoffMissing->value => self::SIGNOFF,
            default => self::for($finding->question_code),
        };
    }
}
