<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use App\Notifications\LeadReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketingController extends Controller
{
    public const PAGES = [
        'home' => ['Business feels better when it’s connected.', 'One workspace for your business, from a thoughtful setup to the next stage of growth.'],
        'features' => ['A foundation built around your business.', 'Explore business configuration, team permissions and transparent subscription management.'],
        'industries' => ['Different businesses. A familiar workspace.', 'Personalize your setup with 23 industry templates, from neighborhood stores to service businesses.'],
        'pos-software' => ['Make everyday selling simpler.', 'Our point of sale roadmap brings sales, receipts and register workflows into one business workspace.'],
        'inventory-management' => ['A clearer picture of your stock.', 'The inventory roadmap covers products, purchasing, stock movements and warehouse operations.'],
        'restaurant-pos' => ['From the first order to the last table.', 'Configure restaurant terminology and discover planned tables, kitchen orders and menu workflows.'],
        'pharmacy-pos' => ['Careful inventory for careful work.', 'A pharmacy setup with medicine terminology and planned batch and expiry tracking.'],
        'retail-pos' => ['Built for the rhythm of retail.', 'Set up your retail business, organize team access and choose the modules you need as you grow.'],
        'pricing' => ['Room to grow. On your terms.', 'A permanent Free plan, with transparent monthly Standard and Premium pricing in PKR.'],
        'about' => ['Thoughtful tools for everyday business.', 'Tech POS is being built to give independent businesses a connected, adaptable workspace.'],
        'contact' => ['Let’s talk about your business.', 'Tell us what you need. We’ll keep your request in our team’s inbox.'],
        'blog' => ['Notes for better business.', 'Product updates and practical guides will appear here as the platform develops.'],
        'help-center' => ['A little help goes a long way.', 'Get started with business setup, permissions and manual subscription payments.'],
        'privacy-policy' => ['Your privacy matters.', 'This development notice explains the information collected by the current foundation.'],
        'terms-of-service' => ['Clear expectations. Better relationships.', 'These development terms describe the current service and its limitations.'],
    ];

    public function page(string $page = 'home')
    {
        abort_unless(isset(self::PAGES[$page]), 404);

        return view('marketing.page', ['page' => $page, 'title' => self::PAGES[$page][0], 'description' => self::PAGES[$page][1],
            'plans' => Plan::with('prices')->where('active', true)->get(), 'industries' => DB::table('industries')->get()]);
    }

    public function lead(Request $r)
    {
        $d = $r->validate(['name' => 'nullable|required_unless:type,newsletter|string|max:100', 'email' => ['required', 'email', 'max:254', 'not_regex:/[\r\n]/'], 'type' => 'required|in:contact,demo,newsletter', 'message' => 'nullable|required_unless:type,newsletter|string|min:10|max:5000', 'newsletter_consent' => 'sometimes|accepted', 'website' => 'nullable|max:0', 'utm_source' => 'nullable|string|max:100', 'utm_medium' => 'nullable|string|max:100', 'utm_campaign' => 'nullable|string|max:100']);
        if ($d['type'] === 'newsletter' && ! $r->boolean('newsletter_consent')) {
            return back()->withErrors(['newsletter_consent' => 'Please consent to newsletter emails.'])->withInput();
        }
        DB::table('leads')->insert(['name' => $d['name'] ?? null, 'email' => strtolower($d['email']), 'type' => $d['type'], 'message' => $d['message'] ?? null, 'newsletter_consent' => $r->boolean('newsletter_consent'), 'attribution' => json_encode(array_intersect_key($d, array_flip(['utm_source', 'utm_medium', 'utm_campaign']))), 'created_at' => now(), 'updated_at' => now()]);
        foreach (User::where('is_superadmin', true)->whereNotNull('email_verified_at')->get() as $admin) {
            $admin->notify(new LeadReceived($d));
        }

        return back()->with('success', 'Thank you. Your request has been received.');
    }

    public function sitemap()
    {
        return response()->view('marketing.sitemap', ['pages' => array_keys(self::PAGES)])->header('Content-Type', 'application/xml');
    }
}
