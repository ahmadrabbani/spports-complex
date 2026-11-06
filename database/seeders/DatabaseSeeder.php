<?php

namespace Database\Seeders;

use App\Models\AppointmentPurpose;
use App\Models\AppointmentSchedule;
use App\Models\FeeType;
use App\Models\MembershipPlan;
use App\Models\SportsComplex;
use App\Models\SportsComplexImage;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ReconciliationAccountSeeder::class);

        User::updateOrCreate(['email' => 'admin@sports.local'], ['name' => 'System Administrator', 'password' => 'password', 'role' => 'admin', 'is_active' => true]);

        $complexes = [
            ['Canal Bank Sports Complex', 'CW', '21070001', 'Next to DHA EME, Canal Road, Lahore'],
            ['Gulberg Sports Complex', 'GB', '21040001', 'Gurumangat Road, Gulberg, Lahore'],
            ['Sabzazar Sports Complex', 'SZ', '21030002', 'Near Indus Hospital, Sabzazar, Lahore'],
            ['Islamia Ground Sports Complex', 'IG', '21050001', 'Sant Nagar, Islampura, Lahore'],
            ['China Scheme Sports Complex', 'CS', '21090001', 'Multan Road, Lahore'],
            ['Minar-e-Pakistan Sports Complex', 'MP', '21080001', 'Circular Road, Lahore'],
            // The supplied sheet gives a range (21010001-21010004), not one safe posting value.
            ['Johar Town Sports Complex', 'JT', null, '460, Block D-II, M.A. Johar Town, Lahore'],
            ['Tajpura Sports Complex', 'TP', '21060001', '112-Block-C, Tajpura, Lahore'],
            ['LDA Pedal Arena', 'PA', '21100001', 'LDA Pedal Arena, Lahore'],
            ['Hockey Stadium', 'HS', '22010001', 'LDA Hockey Stadium, Lahore'],
        ];

        $jtOptions = [];
        for ($i = 1; $i <= 4; $i++) {
            $jtOptions[] = ['id' => 'JT-'.$i, 'label' => 'Johar Town center '.$i, 'cost_center' => str_pad('2101000'.$i, 10, '0', STR_PAD_LEFT), 'profit_center' => str_pad('2101000'.$i, 9, '0', STR_PAD_LEFT)];
        }
        foreach ($complexes as [$name, $code, $center, $address]) {
            $complex = SportsComplex::updateOrCreate(['code' => $code], [
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => "A welcoming sports and fitness destination serving the {$name} community in Lahore.",
                'opening_hours' => 'Monday–Saturday, 9:00 AM–5:00 PM',
                'address' => $address,
                'cost_center' => $center,
                'profit_center' => $center,
                'sap_center_options' => $code === 'JT' ? $jtOptions : null,
            ]);
            AppointmentSchedule::updateOrCreate(['sports_complex_id' => $complex->id], [
                'opening_time' => '09:00', 'closing_time' => '17:00', 'slot_duration_minutes' => 30,
                'max_appointments_per_slot' => 2, 'working_days' => [1, 2, 3, 4, 5, 6], 'is_active' => true,
            ]);
        }

        $officialImages = [
            'CW' => ['/images/sports-complexes/canal-west.jpeg', '/images/sports-complexes/canal-west-ai.webp', 'https://lsc.org.pk/canal-west-branch.jpeg'],
            'GB' => ['/images/sports-complexes/gulberg.jpg', '/images/sports-complexes/gulberg-ai.webp', 'https://lsc.org.pk/imgi_14_gulber-1-1.jpg'],
            'SZ' => ['/images/sports-complexes/sabzazar.jpg', '/images/sports-complexes/sabzazar-ai.webp', 'https://lsc.org.pk/sabzazar_pic1.jpg'],
            'IG' => ['/images/sports-complexes/islamia-ground.png', '/images/sports-complexes/islamia-ground-ai.webp', 'https://lsc.org.pk/islamia-ground.png'],
            'CS' => ['/images/sports-complexes/china-scheme.jpeg', '/images/sports-complexes/china-scheme-ai.webp', 'https://lsc.org.pk/china-scheme-branch.jpeg'],
            'MP' => ['/images/sports-complexes/minar-e-pakistan.jpg', '/images/sports-complexes/minar-e-pakistan-ai.webp', 'https://lsc.org.pk/lda-minarr-compressed-scaled-1.jpg'],
            'JT' => ['/images/sports-complexes/johar-town.jpeg', '/images/sports-complexes/johar-town-ai.webp', 'https://lsc.org.pk/johar-town-branch.jpeg'],
            'TP' => ['/images/sports-complexes/tajpura.png', '/images/sports-complexes/tajpura-ai.webp', 'https://lsc.org.pk/tajpura-branch.png'],
        ];
        foreach ($officialImages as $code => [$originalUrl, $enhancedUrl, $sourceUrl]) {
            $complex = SportsComplex::where('code', $code)->firstOrFail();
            SportsComplexImage::updateOrCreate(
                ['sports_complex_id' => $complex->id, 'url' => $originalUrl],
                ['alt_text' => $complex->name.' original photograph', 'source_url' => $sourceUrl, 'sort_order' => 1, 'is_featured' => false]
            );
            $featuredImage = SportsComplexImage::updateOrCreate(
                ['sports_complex_id' => $complex->id, 'url' => $enhancedUrl],
                ['alt_text' => $complex->name.' AI-enhanced architectural view', 'source_url' => $sourceUrl, 'sort_order' => 2, 'is_featured' => true]
            );
            $complex->images()->whereKeyNot($featuredImage->id)->update(['is_featured' => false]);
            $complex->update(['image_url' => $enhancedUrl]);
        }

        foreach ([
            ['New Membership', 'Start a new sports complex membership application.', 'sparkles'],
            ['Membership Renewal', 'Renew or update an existing membership.', 'refresh'],
            ['Document Submission', 'Submit or verify application documents.', 'file'],
            ['File Processing', 'Discuss an existing application or file.', 'folder'],
            ['Fee / Challan Matter', 'Get help with fees or an SAP challan.', 'receipt'],
            ['Sports Facility Inquiry', 'Ask about facilities, access, and availability.', 'activity'],
            ['Other', 'Any other appointment request.', 'help'],
        ] as [$name, $description, $icon]) {
            AppointmentPurpose::updateOrCreate(['name' => $name], ['description' => $description, 'icon' => $icon, 'is_active' => true]);
        }

        FeeType::whereIn('code', ['FM_GENERAL_PUBLIC', 'IM_GENERAL_PUBLIC', 'FREEZE_CUSTOMERS', 'FM_GOVT_OFFICERS', 'MEMBERS_CHILDREN', 'IM_GOVT_OFFICERS', 'FEMERGY_MEMBERSHIP', 'PLATINUM_MEMBERSHIP', 'STUDENT_MEMBERSHIP', 'GUEST_USER', 'LDA_EMPLOYEE'])->update(['active' => false]);
        $feeTypes = [
            ['MONTHLY_FEE', 'Monthly Fee', 'Monthly Fee', '610400'],
            ['ARREARS_MONTHLY_FEE', 'Arrears Monthly Fee', 'Arrears Monthly Fee', '610403'],
            ['FINE_MONTHLY_FEE', 'Fine Monthly Fee', 'Fine Monthly Fee', '610402'],
            ['FORM_FEE', 'Form Fee', 'Form Fee', '610404'],
            ['CARD_FEE', 'Card Fee', 'Card Fee', '610401'],
            ['MEMBERSHIP_REGISTRATION_FEE', 'Membership Registration Fee', 'Membership Registration Fee', '610357'],
        ];
        foreach ($feeTypes as [$code, $name, $short, $glAccount]) {
            FeeType::updateOrCreate(['code' => $code], [
                'name' => $name,
                'short_text' => $short,
                'gl_account' => $glAccount,
                // Charge GL accounts follow the approved fee schedule. SAP reacon remains member-specific.
                'reconciliation_account' => null,
                'active' => true,
            ]);
        }

        foreach ([['Individual', 12, 0, 2500, 'monthly'], ['Family', 12, 0, 5000, 'monthly'], ['Annual', 12, 0, 25000, 'yearly']] as [$name,$months,$joining,$fee,$cycle]) {
            MembershipPlan::firstOrCreate(['name' => $name, 'sports_complex_id' => null], ['duration_months' => $months, 'joining_fee' => $joining, 'recurring_fee' => $fee, 'billing_cycle' => $cycle]);
        }
    }
}
