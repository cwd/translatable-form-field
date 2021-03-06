<?php 

namespace Bnh\TranslatableFieldBundle\Helpers;

use Symfony\Bridge\Doctrine\RegistryInterface as RegistryInterface;
use Symfony\Component\Form\Form as Form;
use Symfony\Component\PropertyAccess\PropertyAccess as PropertyAccess;
use Doctrine\ORM\Query as Query;
use Gedmo\Translatable\TranslatableListener as TranslatableListener;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation as AbstractPersonalTranslation;

class TranslatableFieldManager
{

    CONST GEDMO_TRANSLATION = 'Gedmo\\Translatable\\Entity\\Translation';
    CONST GEDMO_TRANSLATION_WALKER = 'Gedmo\\Translatable\\Query\\TreeWalker\\TranslationWalker';
    CONST GEDMO_PERSONAL_TRANSLATIONS_GET = 'getTranslations';
    CONST GEDMO_PERSONAL_TRANSLATIONS_SET = 'addTranslation';
    
    protected $em;
    private $translationRepository;
    
    public function __construct(RegistryInterface $reg)
    {
        $this->em = $reg->getManager();
        $this->translationRepository = $this->em->getRepository(self::GEDMO_TRANSLATION);
    }

    /* @var $translation AbstractPersonalTranslation */
    private function getTranslations($entity)
    {
        // 'personal' translations (separate table for each entity)
        if(\method_exists($entity, self::GEDMO_PERSONAL_TRANSLATIONS_GET) && \is_callable(array($entity, self::GEDMO_PERSONAL_TRANSLATIONS_GET)))
        {
            $translations = array();
            foreach($entity->{self::GEDMO_PERSONAL_TRANSLATIONS_GET}() as $translation)
            {
                $translations[$translation->getLocale()] = $translation->getContent();
            }
            
            return $translations;
        }
        // 'basic' translations (ext_translations table)
        else
        {
            return \array_map(function($element) {return \array_shift($element);}, $this->translationRepository->findTranslations($entity));
        }
    }

    private function getEntityInDefaultLocale($entity, $defaultLocale)
    {
        $class = \get_class($entity);
        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $identifierField = $this->em->getClassMetadata($class)->getIdentifier()[0]; // <- none composite keys only
        $identifierValue = $propertyAccessor->getValue($entity, $identifierField);
        try {
            $entityInDefaultLocale = $this->em->getRepository($class)->createQueryBuilder('entity')
                ->select("entity")
                ->where("entity.$identifierField = :identifier")
                ->setParameter('identifier', $identifierValue)
                ->setMaxResults(1)
                ->getQuery()
                ->useQueryCache(false)
                ->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::GEDMO_TRANSLATION_WALKER)
                ->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $defaultLocale)
                ->getSingleResult();
        } catch (\Exception $e) {
            // Fallback when empty
            $entityInDefaultLocale = new $class;
        }
        return $entityInDefaultLocale;
    }
    
    // SELECT
    public function getTranslatedFields($entity, $fieldName, $defaultLocale)
    {        
        // 1/3 entity in default locale
        $entityInDefaultLocale = $this->getEntityInDefaultLocale($entity, $defaultLocale);

        // 2/3 translations
        $translations = $this->getTranslations($entity);

        // 3/3 translations + default
        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $translations[$defaultLocale] = $propertyAccessor->getValue($entityInDefaultLocale, $fieldName);
        
        return $translations;
    }

    private function getPersonalTranslationClassName($entity)
    {
        $metadata = $this->em->getClassMetadata(\get_class($entity));
        return $metadata->getAssociationTargetClass('translations');
    }
    
    // UPDATE
    public function persistTranslations(Form $form, $locales, $defaultLocale)
    {
        $entity = $form->getParent()->getData();
        $fieldName = $form->getName();
        $submittedValues = $form->getData();

        foreach ($locales as $locale) {
            if (array_key_exists($locale, $submittedValues) && (($value = $submittedValues[$locale]) !== NULL)) {
                // personal
                if(\method_exists($entity, self::GEDMO_PERSONAL_TRANSLATIONS_SET) && \is_callable(array($entity, self::GEDMO_PERSONAL_TRANSLATIONS_SET)))
                {
                    $translationClassName = $this->getPersonalTranslationClassName($entity);
                    $entity->{self::GEDMO_PERSONAL_TRANSLATIONS_SET}(new $translationClassName($locale, $fieldName, $value));
                }
                // 'ext_translations'
                else
                {
                    $this->translationRepository->translate($entity, $fieldName, $locale, $value);
                }
            }
        }

        $this->em->persist($entity);
        $this->em->flush();
    }
}
